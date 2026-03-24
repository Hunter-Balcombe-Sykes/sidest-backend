<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class CommissionPayoutService
{
    private StripeClient $stripe;
    private float $platformFeePercent;
    private int $holdDays;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret_key'));
        $this->platformFeePercent = config('comet.store.platform_fee_percent', 3);
        $this->holdDays = config('comet.store.payout_hold_days', 7);
    }

    /**
     * Main entry point: find all eligible unpaid commissions, batch them,
     * and process payouts.
     */
    public function processEligiblePayouts(): array
    {
        $cutoff = now()->subDays($this->holdDays);
        $stats = ['batches_created' => 0, 'batches_processed' => 0, 'batches_failed' => 0, 'total_cents' => 0];

        // Find all unpaid accrual entries that have passed the hold period,
        // grouped by (brand, affiliate, currency).
        $groups = CommissionLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('entry_type', 'accrual')
            ->where('status', 'approved')
            ->where('occurred_at', '<=', $cutoff)
            ->select([
                'brand_professional_id',
                'affiliate_professional_id',
                'currency_code',
                DB::raw('SUM(amount_cents) as total_cents'),
                DB::raw('COUNT(*) as entry_count'),
            ])
            ->groupBy('brand_professional_id', 'affiliate_professional_id', 'currency_code')
            ->having(DB::raw('SUM(amount_cents)'), '>', 0)
            ->get();

        foreach ($groups as $group) {
            try {
                $payout = $this->createPayoutBatch(
                    $group->brand_professional_id,
                    $group->affiliate_professional_id,
                    $group->currency_code,
                    $cutoff,
                );

                if ($payout) {
                    $stats['batches_created']++;
                    $result = $this->processPayoutBatch($payout);
                    if ($result) {
                        $stats['batches_processed']++;
                        $stats['total_cents'] += $payout->net_payout_cents;
                    } else {
                        $stats['batches_failed']++;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Commission payout batch failed', [
                    'brand_id' => $group->brand_professional_id,
                    'affiliate_id' => $group->affiliate_professional_id,
                    'currency' => $group->currency_code,
                    'error' => $e->getMessage(),
                ]);
                $stats['batches_failed']++;
            }
        }

        return $stats;
    }

    /**
     * Create a payout batch record and link all eligible ledger entries.
     */
    private function createPayoutBatch(
        string $brandId,
        string $affiliateId,
        string $currency,
        \DateTimeInterface $cutoff,
    ): ?CommissionPayout {
        return DB::transaction(function () use ($brandId, $affiliateId, $currency, $cutoff) {
            // Lock and fetch eligible entries
            $entries = CommissionLedgerEntry::query()
                ->whereNull('payout_id')
                ->where('entry_type', 'accrual')
                ->where('status', 'approved')
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('currency_code', $currency)
                ->where('occurred_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty()) {
                return null;
            }

            $grossCents = $entries->sum('amount_cents');
            if ($grossCents <= 0) {
                return null;
            }

            // Also sum any unpaid reversals for this group to net out
            $reversalCents = CommissionLedgerEntry::query()
                ->whereNull('payout_id')
                ->where('entry_type', 'reversal')
                ->where('status', 'approved')
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('currency_code', $currency)
                ->where('occurred_at', '<=', $cutoff)
                ->lockForUpdate()
                ->sum('amount_cents'); // negative values

            $netCommission = $grossCents + $reversalCents; // reversals are negative
            if ($netCommission <= 0) {
                return null;
            }

            $platformFeeCents = (int) round($netCommission * $this->platformFeePercent / 100);
            $netPayoutCents = $netCommission - $platformFeeCents;

            if ($netPayoutCents <= 0) {
                return null;
            }

            $payout = CommissionPayout::create([
                'brand_professional_id' => $brandId,
                'affiliate_professional_id' => $affiliateId,
                'status' => 'pending',
                'gross_commission_cents' => $netCommission,
                'platform_fee_cents' => $platformFeeCents,
                'net_payout_cents' => $netPayoutCents,
                'currency_code' => $currency,
                'ledger_entry_count' => $entries->count(),
                'eligible_after' => $cutoff,
            ]);

            // Link accrual entries
            foreach ($entries as $entry) {
                CommissionPayoutItem::create([
                    'payout_id' => $payout->id,
                    'commission_ledger_entry_id' => $entry->id,
                    'amount_cents' => $entry->amount_cents,
                ]);
                $entry->update(['payout_id' => $payout->id]);
            }

            // Link reversal entries
            $reversals = CommissionLedgerEntry::query()
                ->whereNull('payout_id')
                ->where('entry_type', 'reversal')
                ->where('status', 'approved')
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('currency_code', $currency)
                ->where('occurred_at', '<=', $cutoff)
                ->get();

            foreach ($reversals as $reversal) {
                CommissionPayoutItem::create([
                    'payout_id' => $payout->id,
                    'commission_ledger_entry_id' => $reversal->id,
                    'amount_cents' => $reversal->amount_cents,
                ]);
                $reversal->update(['payout_id' => $payout->id]);
            }

            return $payout;
        });
    }

    /**
     * Process a single payout batch: charge the brand, transfer to the affiliate.
     */
    private function processPayoutBatch(CommissionPayout $payout): bool
    {
        $brand = Professional::find($payout->brand_professional_id);
        $affiliate = Professional::find($payout->affiliate_professional_id);

        // Validate both parties have Stripe setup
        if (! $brand?->stripe_customer_id || ! $brand?->stripe_payment_method_id) {
            $this->failPayout($payout, 'brand_no_payment_method', 'Brand has no payment method configured');
            return false;
        }

        if (! $affiliate?->stripe_connect_account_id || $affiliate->stripe_connect_status !== 'active') {
            $this->failPayout($payout, 'affiliate_not_connected', 'Affiliate Stripe Connect account is not active');
            return false;
        }

        $currencyLower = strtolower($payout->currency_code);

        // Step 1: Charge the brand
        try {
            $payout->update(['status' => 'collecting']);

            $paymentIntent = $this->stripe->paymentIntents->create([
                'amount' => $payout->gross_commission_cents,
                'currency' => $currencyLower,
                'customer' => $brand->stripe_customer_id,
                'payment_method' => $brand->stripe_payment_method_id,
                'off_session' => true,
                'confirm' => true,
                'description' => "Commission payout #{$payout->id} to {$affiliate->display_name}",
                'metadata' => [
                    'comet_payout_id' => $payout->id,
                    'brand_id' => $brand->id,
                    'affiliate_id' => $affiliate->id,
                ],
            ]);

            $payout->update([
                'stripe_payment_intent_id' => $paymentIntent->id,
                'status' => 'collected',
            ]);
        } catch (ApiErrorException $e) {
            $this->failPayout($payout, 'charge_failed', $e->getMessage());
            return false;
        }

        // Step 2: Transfer to the affiliate (minus platform fee)
        try {
            $payout->update(['status' => 'transferring']);

            $transfer = $this->stripe->transfers->create([
                'amount' => $payout->net_payout_cents,
                'currency' => $currencyLower,
                'destination' => $affiliate->stripe_connect_account_id,
                'description' => "Commission from {$brand->display_name}",
                'metadata' => [
                    'comet_payout_id' => $payout->id,
                    'brand_id' => $brand->id,
                    'affiliate_id' => $affiliate->id,
                ],
            ]);

            $payout->update([
                'stripe_transfer_id' => $transfer->id,
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            Log::info('Commission payout completed', [
                'payout_id' => $payout->id,
                'gross_cents' => $payout->gross_commission_cents,
                'platform_fee_cents' => $payout->platform_fee_cents,
                'net_cents' => $payout->net_payout_cents,
                'currency' => $payout->currency_code,
            ]);

            return true;
        } catch (ApiErrorException $e) {
            $this->failPayout($payout, 'transfer_failed', $e->getMessage());
            return false;
        }
    }

    private function failPayout(CommissionPayout $payout, string $code, string $reason): void
    {
        $payout->update([
            'status' => 'failed',
            'failure_code' => $code,
            'failure_reason' => $reason,
        ]);

        Log::warning('Commission payout failed', [
            'payout_id' => $payout->id,
            'code' => $code,
            'reason' => $reason,
        ]);
    }

    /**
     * Retry a failed payout batch.
     */
    public function retryPayout(CommissionPayout $payout): bool
    {
        if ($payout->status !== 'failed') {
            return false;
        }

        $payout->update([
            'status' => 'pending',
            'failure_code' => null,
            'failure_reason' => null,
        ]);

        return $this->processPayoutBatch($payout);
    }

    /**
     * Get payout summary for a professional (as brand or affiliate).
     */
    public function getPayoutSummary(Professional $professional): array
    {
        $asBrand = CommissionPayout::query()
            ->where('brand_professional_id', $professional->id)
            ->selectRaw("status, COUNT(*) as count, SUM(gross_commission_cents) as total_cents")
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $asAffiliate = CommissionPayout::query()
            ->where('affiliate_professional_id', $professional->id)
            ->selectRaw("status, COUNT(*) as count, SUM(net_payout_cents) as total_cents")
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'as_brand' => $asBrand,
            'as_affiliate' => $asAffiliate,
        ];
    }
}
