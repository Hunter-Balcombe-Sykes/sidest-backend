<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

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
        $stats = [
            'batches_created' => 0,
            'existing_batches_retried' => 0,
            'batches_processed' => 0,
            'batches_failed' => 0,
            'batches_pending_funding' => 0,
            'total_cents' => 0,
        ];

        // Retry previously created batches that are still unresolved.
        $existingPending = CommissionPayout::query()
            ->where('status', 'pending')
            ->whereNull('processed_at')
            ->orderBy('eligible_after')
            ->limit(500)
            ->get();

        foreach ($existingPending as $pendingPayout) {
            try {
                $stats['existing_batches_retried']++;
                $result = $this->processPayoutBatch($pendingPayout);

                if ($result === true) {
                    $stats['batches_processed']++;
                    $stats['total_cents'] += $pendingPayout->net_payout_cents;
                    continue;
                }

                if ($result === null) {
                    $stats['batches_pending_funding']++;
                    continue;
                }

                $stats['batches_failed']++;
            } catch (\Throwable $e) {
                Log::error('Retrying existing commission payout batch failed', [
                    'payout_id' => $pendingPayout->id,
                    'error' => $e->getMessage(),
                ]);
                $stats['batches_failed']++;
            }
        }

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

                if (! $payout) {
                    continue;
                }

                $stats['batches_created']++;
                $result = $this->processPayoutBatch($payout);

                if ($result === true) {
                    $stats['batches_processed']++;
                    $stats['total_cents'] += $payout->net_payout_cents;
                    continue;
                }

                if ($result === null) {
                    $stats['batches_pending_funding']++;
                    continue;
                }

                $stats['batches_failed']++;
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

            $reversalCents = CommissionLedgerEntry::query()
                ->whereNull('payout_id')
                ->where('entry_type', 'reversal')
                ->where('status', 'approved')
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('currency_code', $currency)
                ->where('occurred_at', '<=', $cutoff)
                ->lockForUpdate()
                ->sum('amount_cents');

            $netCommission = $grossCents + $reversalCents;
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
                'currency_code' => strtoupper($currency),
                'ledger_entry_count' => $entries->count(),
                'eligible_after' => $cutoff,
            ]);

            foreach ($entries as $entry) {
                CommissionPayoutItem::create([
                    'payout_id' => $payout->id,
                    'commission_ledger_entry_id' => $entry->id,
                    'amount_cents' => $entry->amount_cents,
                ]);
                $entry->update(['payout_id' => $payout->id]);
            }

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
     * Process payout with hybrid funding:
     * 1) Use brand manual top-up balance when sufficient.
     * 2) Else auto-charge saved payment method (if mode allows).
     * 3) If neither is available, keep payout pending for funding.
     *
     * Return values:
     * - true: completed
     * - null: pending for funding/retry
     * - false: failed
     */
    private function processPayoutBatch(CommissionPayout $payout): ?bool
    {
        $brand = Professional::find($payout->brand_professional_id);
        $affiliate = Professional::find($payout->affiliate_professional_id);

        if (! $brand?->stripe_connect_account_id || $brand->stripe_connect_status !== 'active') {
            $this->failPayout($payout, 'brand_not_connected', 'Brand Stripe Connect account is not active');
            return false;
        }

        if (! $affiliate?->stripe_connect_account_id || $affiliate->stripe_connect_status !== 'active') {
            $this->failPayout($payout, 'affiliate_not_connected', 'Affiliate Stripe Connect account is not active');
            return false;
        }

        $currencyUpper = strtoupper($payout->currency_code);
        $currencyLower = strtolower($currencyUpper);
        $fundingMode = $this->normalizeFundingMode($brand->stripe_commission_funding_mode ?? null);
        $brandBalanceCents = (int) ($brand->stripe_manual_balance_cents ?? 0);
        $brandBalanceCurrency = strtoupper((string) ($brand->stripe_manual_balance_currency ?: $currencyUpper));

        if ($brandBalanceCents > 0 && $brandBalanceCurrency !== $currencyUpper) {
            $this->markPendingFunding(
                $payout,
                'manual_balance_currency_mismatch',
                sprintf(
                    'Brand manual balance currency (%s) does not match payout currency (%s).',
                    $brandBalanceCurrency,
                    $currencyUpper,
                )
            );
            return null;
        }

        $usedManualBalance = false;
        $latestChargeId = null;

        if ($brandBalanceCents >= $payout->gross_commission_cents) {
            $payout->update([
                'status' => 'collecting',
                'failure_code' => null,
                'failure_reason' => null,
            ]);

            if (! $this->debitBrandManualBalance($brand->id, $payout->gross_commission_cents, $currencyUpper)) {
                $this->markPendingFunding(
                    $payout,
                    'manual_balance_unavailable',
                    'Manual top-up balance became unavailable. Please top up and retry.',
                );
                return null;
            }

            $usedManualBalance = true;

            $payout->update([
                'status' => 'collected',
                'failure_code' => null,
                'failure_reason' => null,
            ]);
        } else {
            if ($fundingMode === 'manual_topup') {
                $this->markPendingFunding(
                    $payout,
                    'manual_topup_required',
                    'Insufficient manual top-up balance. Add funds to continue payouts.',
                );
                return null;
            }

            if (! $brand->stripe_customer_id || ! $brand->stripe_payment_method_id) {
                $this->markPendingFunding(
                    $payout,
                    'brand_payment_method_missing',
                    'No brand payment method on file. Add a payment method or switch to manual top-ups.',
                );
                return null;
            }

            try {
                $payout->update([
                    'status' => 'collecting',
                    'failure_code' => null,
                    'failure_reason' => null,
                ]);

                $paymentIntent = $this->stripe->paymentIntents->create([
                    'amount' => $payout->gross_commission_cents,
                    'currency' => $currencyLower,
                    'customer' => $brand->stripe_customer_id,
                    'payment_method' => $brand->stripe_payment_method_id,
                    'confirm' => true,
                    'off_session' => true,
                    'description' => "Commission payout #{$payout->id}",
                    'metadata' => [
                        'comet_payout_id' => $payout->id,
                        'brand_id' => $brand->id,
                        'affiliate_id' => $affiliate->id,
                        'funding_mode' => 'auto_charge',
                    ],
                ]);

                $latestChargeId = $this->extractLatestChargeId($paymentIntent);

                $payout->update([
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'status' => 'collected',
                ]);
            } catch (ApiErrorException $e) {
                $this->markPendingFunding(
                    $payout,
                    'auto_charge_failed',
                    $e->getMessage(),
                );
                return null;
            }
        }

        try {
            $payout->update(['status' => 'transferring']);

            $transferPayload = [
                'amount' => $payout->net_payout_cents,
                'currency' => $currencyLower,
                'destination' => $affiliate->stripe_connect_account_id,
                'description' => "Commission payout #{$payout->id} to {$affiliate->display_name}",
                'metadata' => [
                    'comet_payout_id' => $payout->id,
                    'brand_id' => $brand->id,
                    'affiliate_id' => $affiliate->id,
                ],
            ];

            if ($latestChargeId) {
                $transferPayload['source_transaction'] = $latestChargeId;
            }

            $transfer = $this->stripe->transfers->create($transferPayload);

            $payout->update([
                'stripe_transfer_id' => $transfer->id,
                'status' => 'completed',
                'processed_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
            ]);

            Log::info('Commission payout completed', [
                'payout_id' => $payout->id,
                'gross_cents' => $payout->gross_commission_cents,
                'platform_fee_cents' => $payout->platform_fee_cents,
                'net_cents' => $payout->net_payout_cents,
                'currency' => $payout->currency_code,
                'funding_mode' => $usedManualBalance ? 'manual_topup' : 'auto_charge',
            ]);

            return true;
        } catch (ApiErrorException $e) {
            if ($usedManualBalance) {
                $this->creditBrandManualBalance($brand->id, $payout->gross_commission_cents, $currencyUpper);
            }

            $this->failPayout($payout, 'transfer_failed', $e->getMessage());
            return false;
        }
    }

    private function debitBrandManualBalance(string $brandId, int $amountCents, string $currencyCode): bool
    {
        if ($amountCents <= 0) {
            return true;
        }

        return (bool) DB::transaction(function () use ($brandId, $amountCents, $currencyCode) {
            $brand = Professional::query()
                ->whereKey($brandId)
                ->lockForUpdate()
                ->first();

            if (! $brand) {
                return false;
            }

            $balance = (int) ($brand->stripe_manual_balance_cents ?? 0);
            $walletCurrency = strtoupper((string) ($brand->stripe_manual_balance_currency ?: $currencyCode));

            if ($walletCurrency !== strtoupper($currencyCode) || $balance < $amountCents) {
                return false;
            }

            $brand->stripe_manual_balance_cents = $balance - $amountCents;
            $brand->save();

            return true;
        });
    }

    private function creditBrandManualBalance(string $brandId, int $amountCents, string $currencyCode): void
    {
        if ($amountCents <= 0) {
            return;
        }

        DB::transaction(function () use ($brandId, $amountCents, $currencyCode) {
            $brand = Professional::query()
                ->whereKey($brandId)
                ->lockForUpdate()
                ->first();

            if (! $brand) {
                return;
            }

            $balance = (int) ($brand->stripe_manual_balance_cents ?? 0);
            $brand->stripe_manual_balance_cents = $balance + $amountCents;
            $brand->stripe_manual_balance_currency = strtoupper((string) ($brand->stripe_manual_balance_currency ?: $currencyCode));
            $brand->save();
        });
    }

    private function extractLatestChargeId(object $paymentIntent): ?string
    {
        if (is_string($paymentIntent->latest_charge ?? null) && $paymentIntent->latest_charge !== '') {
            return $paymentIntent->latest_charge;
        }

        if (is_object($paymentIntent->latest_charge ?? null) && is_string($paymentIntent->latest_charge->id ?? null)) {
            return $paymentIntent->latest_charge->id;
        }

        if (is_object($paymentIntent->charges ?? null) && is_array($paymentIntent->charges->data ?? null)) {
            foreach ($paymentIntent->charges->data as $charge) {
                if (is_object($charge) && is_string($charge->id ?? null) && $charge->id !== '') {
                    return $charge->id;
                }
            }
        }

        return null;
    }

    private function normalizeFundingMode(?string $mode): string
    {
        return in_array($mode, ['auto_charge', 'manual_topup'], true)
            ? $mode
            : 'auto_charge';
    }

    private function markPendingFunding(CommissionPayout $payout, string $code, string $reason): void
    {
        $payout->update([
            'status' => 'pending',
            'failure_code' => $code,
            'failure_reason' => $reason,
            'processed_at' => null,
        ]);

        Log::notice('Commission payout pending funding', [
            'payout_id' => $payout->id,
            'code' => $code,
            'reason' => $reason,
        ]);
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
        if (! in_array($payout->status, ['failed', 'pending'], true)) {
            return false;
        }

        $payout->update([
            'status' => 'pending',
            'failure_code' => null,
            'failure_reason' => null,
        ]);

        return $this->processPayoutBatch($payout) === true;
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
