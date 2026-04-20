<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\CommissionLedgerEntry;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

// V2: Core. Processes eligible commission payouts with hybrid funding (wallet balance first, card charge for shortfall). Transfers net amount to affiliate via Stripe Connect (80/20 split).
class CommissionPayoutService
{
    private StripeClient $stripe;

    private float $platformFeePercent;

    private int $systemHoldDays;

    private int $minHoldDays;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret_key'));
        $this->platformFeePercent = config('sidest.store.platform_fee_percent', 3);
        $this->systemHoldDays = max(0, (int) config('sidest.store.payout_hold_days', 7));
        $this->minHoldDays = (int) config('sidest.store.min_payout_hold_days', 7);
    }

    /**
     * Main entry point: find all eligible unpaid commissions, batch them,
     * and process payouts.
     */
    public function processEligiblePayouts(): array
    {
        $stats = [
            'batches_created' => 0,
            'existing_batches_retried' => 0,
            'batches_processed' => 0,
            'batches_failed' => 0,
            'batches_pending_funding' => 0,
            'total_cents' => 0,
        ];

        // Retry previously created batches that are still unresolved.
        // 'collecting' and 'transferring' are mid-flight states that can get stuck if a
        // DB write fails after a Stripe call succeeded — idempotency keys make these safe to retry.
        $existingPending = CommissionPayout::query()
            ->whereIn('status', ['pending', 'collecting', 'transferring'])
            ->whereNull('processed_at')
            ->where('eligible_after', '<=', now())
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

        // Find all brands that have unpaid approved accruals, then apply
        // per-brand hold days to determine which entries are eligible.
        $brandIds = CommissionLedgerEntry::query()
            ->whereNull('payout_id')
            ->where('entry_type', 'accrual')
            ->where('status', 'approved')
            ->distinct()
            ->pluck('brand_professional_id');

        // Pre-load brand store settings for hold-day lookups.
        $brandSettings = BrandStoreSettings::query()
            ->whereIn('professional_id', $brandIds)
            ->pluck('payout_hold_days', 'professional_id');

        foreach ($brandIds as $brandId) {
            $holdDays = $this->resolveHoldDays($brandSettings[$brandId] ?? null);
            $cutoff = now()->subDays($holdDays);

            $groups = CommissionLedgerEntry::query()
                ->whereNull('payout_id')
                ->where('entry_type', 'accrual')
                ->where('status', 'approved')
                ->where('brand_professional_id', $brandId)
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
        }

        return $stats;
    }

    /**
     * Resolve the effective hold days for a brand.
     * Uses brand override if set, otherwise system default. Always >= system minimum.
     */
    private function resolveHoldDays(?int $brandPayoutHoldDays): int
    {
        $days = $brandPayoutHoldDays ?? $this->systemHoldDays;

        return max($this->minHoldDays, $days);
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
                // funding_source is set later in processPayoutBatch once we
                // know whether collection was wallet / card / wallet_and_card.
                'funding_source' => null,
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
     * 1) Debit wallet balance first (partial OK).
     * 2) Charge brand's card for any shortfall.
     * 3) Transfer net amount to affiliate.
     *
     * Card is required. Wallet is optional but used as priority.
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

        if (! $affiliate?->stripe_connect_account_id || $affiliate->stripe_connect_status !== 'active') {
            // During grace period or within void window: hold this batch so the
            // void service can handle it on its own schedule. Don't fail permanently.
            $this->markPendingFunding($payout, 'affiliate_not_connected', 'Affiliate Stripe Connect account is not active — holding for grace period');

            return null;
        }

        if (! $brand) {
            $this->failPayout($payout, 'brand_missing', 'Brand account was not found.');

            return false;
        }

        // Card is required — we charge the brand's saved card to collect the
        // commission owed, then transfer the net amount to the affiliate.
        if (! $brand->stripe_customer_id || ! $brand->stripe_payment_method_id) {
            $this->markPendingFunding(
                $payout,
                'brand_payment_method_missing',
                'No payment method on file. Please add a card in your Stripe settings.',
            );

            return null;
        }

        $currencyUpper = strtoupper($payout->currency_code);
        $currencyLower = strtolower($currencyUpper);
        $amountToCollect = $payout->gross_commission_cents;

        // Step 1: Debit wallet balance (as much as available, partial OK)
        $walletDebitCents = $this->debitBrandManualBalancePartial($brand->id, $amountToCollect, $currencyUpper);
        $chargeAmountCents = $amountToCollect - $walletDebitCents;

        $fundingSource = 'card';
        if ($walletDebitCents > 0 && $chargeAmountCents > 0) {
            $fundingSource = 'wallet_and_card';
        } elseif ($walletDebitCents > 0 && $chargeAmountCents <= 0) {
            $fundingSource = 'wallet';
        }

        $payout->update([
            'status' => 'collecting',
            'funding_source' => $fundingSource,
            'wallet_debit_cents' => $walletDebitCents,
            'charge_cents' => $chargeAmountCents,
            'failure_code' => null,
            'failure_reason' => null,
        ]);

        // Step 2: Charge brand's card for the shortfall (if any)
        $latestChargeId = null;
        if ($chargeAmountCents > 0) {
            try {
                // Idempotency key ensures a retry after a DB write failure re-uses the
                // same PaymentIntent rather than double-charging the brand.
                $paymentIntent = $this->stripe->paymentIntents->create([
                    'amount' => $chargeAmountCents,
                    'currency' => $currencyLower,
                    'customer' => $brand->stripe_customer_id,
                    'payment_method' => $brand->stripe_payment_method_id,
                    'confirm' => true,
                    'off_session' => true,
                    'description' => "Commission payout #{$payout->id}",
                    'metadata' => [
                        'sidest_payout_id' => $payout->id,
                        'brand_id' => $brand->id,
                        'affiliate_id' => $affiliate->id,
                    ],
                ], ['idempotency_key' => 'pi_'.$payout->id]);

                if ($paymentIntent->status !== 'succeeded') {
                    // SCA required — refund wallet debit and mark pending
                    if ($walletDebitCents > 0) {
                        $this->creditBrandManualBalance($brand->id, $walletDebitCents, $currencyUpper);
                    }
                    $this->markPendingFunding($payout, 'charge_requires_action', 'Card charge requires authentication.');

                    return null;
                }

                $latestChargeId = $this->extractLatestChargeId($paymentIntent);

                $payout->update([
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'status' => 'collected',
                ]);
            } catch (ApiErrorException $e) {
                // Card charge failed — refund wallet debit
                if ($walletDebitCents > 0) {
                    $this->creditBrandManualBalance($brand->id, $walletDebitCents, $currencyUpper);
                }
                $this->markPendingFunding($payout, 'charge_failed', $e->getMessage());

                return null;
            }
        } else {
            // Fully funded by wallet
            $payout->update(['status' => 'collected']);
        }

        // Step 3: Transfer net amount to the affiliate's Connect account
        try {
            $payout->update(['status' => 'transferring']);

            $transferPayload = [
                'amount' => $payout->net_payout_cents,
                'currency' => $currencyLower,
                'destination' => $affiliate->stripe_connect_account_id,
                'description' => "Commission payout #{$payout->id} to {$affiliate->display_name}",
                'metadata' => [
                    'sidest_payout_id' => $payout->id,
                    'brand_id' => $brand->id,
                    'affiliate_id' => $affiliate->id,
                ],
            ];

            if ($latestChargeId) {
                $transferPayload['source_transaction'] = $latestChargeId;
            }

            // Idempotency key ensures a retry after a DB write failure re-uses the
            // same Transfer rather than double-paying the affiliate.
            $transfer = $this->stripe->transfers->create($transferPayload, ['idempotency_key' => 'tr_'.$payout->id]);

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
                'wallet_debit_cents' => $walletDebitCents,
                'charge_cents' => $chargeAmountCents,
                'platform_fee_cents' => $payout->platform_fee_cents,
                'net_cents' => $payout->net_payout_cents,
                'funding_source' => $fundingSource,
                'currency' => $payout->currency_code,
            ]);

            // Rebuild commerce aggregates so commission_paid_cents is reflected
            \App\Jobs\Analytics\RebuildCommerceDailyAggregatesJob::dispatch(
                (string) $payout->brand_professional_id,
                (string) $payout->affiliate_professional_id,
                now()->toDateString()
            );

            return true;
        } catch (ApiErrorException $e) {
            // Transfer failed — refund wallet debit if used
            // (card refund would need a separate Stripe refund which is more complex,
            //  so we mark failed for manual resolution)
            if ($walletDebitCents > 0) {
                $this->creditBrandManualBalance($brand->id, $walletDebitCents, $currencyUpper);
            }

            $this->failPayout($payout, 'transfer_failed', $e->getMessage());

            return false;
        }
    }

    /**
     * Debit the brand's wallet balance, up to the requested amount (partial OK).
     * Returns the actual amount debited (0 if no balance available).
     */
    private function debitBrandManualBalancePartial(string $brandId, int $requestedCents, string $currencyCode): int
    {
        if ($requestedCents <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($brandId, $requestedCents, $currencyCode) {
            $brand = Professional::query()
                ->whereKey($brandId)
                ->lockForUpdate()
                ->first();

            if (! $brand) {
                return 0;
            }

            $balance = max(0, (int) ($brand->stripe_manual_balance_cents ?? 0));
            $walletCurrency = strtoupper((string) ($brand->stripe_manual_balance_currency ?: $currencyCode));

            if ($walletCurrency !== strtoupper($currencyCode) || $balance <= 0) {
                return 0;
            }

            $debit = min($balance, $requestedCents);
            $brand->stripe_manual_balance_cents = $balance - $debit;
            $brand->save();

            return $debit;
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
            ->selectRaw('status, COUNT(*) as count, SUM(gross_commission_cents) as total_cents')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $asAffiliate = CommissionPayout::query()
            ->where('affiliate_professional_id', $professional->id)
            ->selectRaw('status, COUNT(*) as count, SUM(net_payout_cents) as total_cents')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'as_brand' => $asBrand,
            'as_affiliate' => $asAffiliate,
        ];
    }
}
