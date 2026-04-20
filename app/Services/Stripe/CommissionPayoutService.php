<?php

namespace App\Services\Stripe;

use App\Jobs\Stripe\ExecuteCommissionPayoutJob;
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
     * and dispatch a per-payout job for each. Returns dispatch counts only —
     * actual results are reported per-job via Horizon.
     *
     * @return array{batches_dispatched: int, batches_created: int, batches_requeued: int}
     */
    public function processEligiblePayouts(): array
    {
        $stats = [
            'batches_dispatched' => 0,
            'batches_created'    => 0,
            'batches_requeued'   => 0,
        ];

        // Re-dispatch any in-flight batches. ExecuteCommissionPayoutJob's idempotent
        // resume logic handles collecting/transferring states safely.
        $existingPending = CommissionPayout::query()
            ->whereIn('status', ['pending', 'collecting', 'transferring'])
            ->whereNull('processed_at')
            ->where('eligible_after', '<=', now())
            ->orderBy('eligible_after')
            ->limit(500)
            ->get();

        foreach ($existingPending as $pendingPayout) {
            ExecuteCommissionPayoutJob::dispatch($pendingPayout->id);
            $stats['batches_dispatched']++;
            $stats['batches_requeued']++;
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
                    ExecuteCommissionPayoutJob::dispatch($payout->id);
                    $stats['batches_dispatched']++;
                } catch (\Throwable $e) {
                    Log::error('Commission payout batch creation failed', [
                        'brand_id'     => $group->brand_professional_id,
                        'affiliate_id' => $group->affiliate_professional_id,
                        'currency'     => $group->currency_code,
                        'error'        => $e->getMessage(),
                    ]);
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
                'brand_professional_id'    => $brandId,
                'affiliate_professional_id'=> $affiliateId,
                'status'                   => 'pending',
                'gross_commission_cents'   => $netCommission,
                'platform_fee_cents'       => $platformFeeCents,
                'net_payout_cents'         => $netPayoutCents,
                'currency_code'            => strtoupper($currency),
                'ledger_entry_count'       => $entries->count(),
                'eligible_after'           => $cutoff,
                'funding_source'           => null,
            ]);

            foreach ($entries as $entry) {
                CommissionPayoutItem::create([
                    'payout_id'                   => $payout->id,
                    'commission_ledger_entry_id'   => $entry->id,
                    'amount_cents'                 => $entry->amount_cents,
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
                    'payout_id'                   => $payout->id,
                    'commission_ledger_entry_id'   => $reversal->id,
                    'amount_cents'                 => $reversal->amount_cents,
                ]);
                $reversal->update(['payout_id' => $payout->id]);
            }

            return $payout;
        });
    }

    /**
     * Process a single payout through the full 3-step flow with idempotent resume:
     *   1) Debit wallet balance (atomic with status update — crash-safe).
     *   2) Charge brand's card for any shortfall.
     *   3) Transfer net amount to affiliate via Stripe Connect.
     *
     * Each step checks the payout's current status before executing, so retries
     * (both Horizon automatic and admin manual) resume from where they left off
     * rather than restarting from scratch. This prevents double-debiting the wallet
     * and double-charging the brand's card.
     *
     * Return values:
     * - true:  completed
     * - null:  pending for funding/retry
     * - false: failed
     */
    public function processPayoutBatch(CommissionPayout $payout): ?bool
    {
        if ($payout->status === 'completed') {
            return true;
        }

        $brand = Professional::find($payout->brand_professional_id);
        $affiliate = Professional::find($payout->affiliate_professional_id);

        if (! $affiliate?->stripe_connect_account_id || $affiliate->stripe_connect_status !== 'active') {
            $this->markPendingFunding($payout, 'affiliate_not_connected', 'Affiliate Stripe Connect account is not active — holding for grace period');

            return null;
        }

        if (! $brand) {
            $this->failPayout($payout, 'brand_missing', 'Brand account was not found.');

            return false;
        }

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

        // Idempotency key suffix — retry_count is incremented on each admin manual retry
        // so fresh Stripe objects are created rather than returning a refunded/failed one
        // from within the 24-hour idempotency TTL window.
        $retryKey = '_r'.$payout->retry_count;

        // Step 1: Wallet debit — or resume from recorded state
        //
        // The debit and the status update are wrapped in a single DB::transaction
        // so a process crash between them can't leave the wallet reduced but the
        // payout record still showing 'pending' (which would cause a double-debit on retry).
        $walletDebitCents = 0;
        $chargeAmountCents = $amountToCollect;

        if (in_array($payout->status, ['collecting', 'collected', 'transferring'], true)) {
            // Wallet debit already committed in a previous run — read from DB, don't re-debit.
            $walletDebitCents = (int) ($payout->wallet_debit_cents ?? 0);
            $chargeAmountCents = (int) ($payout->charge_cents ?? $amountToCollect);
        } else {
            DB::transaction(function () use ($payout, $brand, $amountToCollect, $currencyUpper, &$walletDebitCents, &$chargeAmountCents): void {
                $walletDebitCents = $this->debitBrandManualBalancePartial($brand->id, $amountToCollect, $currencyUpper);
                $chargeAmountCents = $amountToCollect - $walletDebitCents;

                $fundingSource = 'card';
                if ($walletDebitCents > 0 && $chargeAmountCents > 0) {
                    $fundingSource = 'wallet_and_card';
                } elseif ($walletDebitCents > 0) {
                    $fundingSource = 'wallet';
                }

                $payout->update([
                    'status'             => 'collecting',
                    'funding_source'     => $fundingSource,
                    'wallet_debit_cents' => $walletDebitCents,
                    'charge_cents'       => $chargeAmountCents,
                    'failure_code'       => null,
                    'failure_reason'     => null,
                ]);
            });
        }

        // Step 2: Charge brand's card for the shortfall (if any)
        $latestChargeId = null;

        if (in_array($payout->status, ['collected', 'transferring'], true)) {
            // PaymentIntent already succeeded in a previous run.
            // Retrieve the charge ID so we can use source_transaction on the transfer.
            if ($payout->stripe_payment_intent_id && $chargeAmountCents > 0) {
                $pi = $this->stripe->paymentIntents->retrieve($payout->stripe_payment_intent_id);
                $latestChargeId = $this->extractLatestChargeId($pi);
            }
        } elseif ($chargeAmountCents > 0) {
            try {
                $paymentIntent = $this->stripe->paymentIntents->create([
                    'amount'         => $chargeAmountCents,
                    'currency'       => $currencyLower,
                    'customer'       => $brand->stripe_customer_id,
                    'payment_method' => $brand->stripe_payment_method_id,
                    'confirm'        => true,
                    'off_session'    => true,
                    'description'    => "Commission payout #{$payout->id}",
                    'metadata'       => [
                        'sidest_payout_id' => $payout->id,
                        'brand_id'         => $brand->id,
                        'affiliate_id'     => $affiliate->id,
                    ],
                ], ['idempotency_key' => 'pi_'.$payout->id.$retryKey]);

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
                    'status'                   => 'collected',
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
            // Fully funded by wallet — only advance status if not already past this step
            if (! in_array($payout->status, ['collected', 'transferring'], true)) {
                $payout->update(['status' => 'collected']);
            }
        }

        // Step 3: Transfer net amount to the affiliate's Connect account
        try {
            if ($payout->status !== 'transferring') {
                $payout->update(['status' => 'transferring']);
            }

            $transferPayload = [
                'amount'      => $payout->net_payout_cents,
                'currency'    => $currencyLower,
                'destination' => $affiliate->stripe_connect_account_id,
                'description' => "Commission payout #{$payout->id} to {$affiliate->display_name}",
                'metadata'    => [
                    'sidest_payout_id' => $payout->id,
                    'brand_id'         => $brand->id,
                    'affiliate_id'     => $affiliate->id,
                ],
            ];

            if ($latestChargeId) {
                $transferPayload['source_transaction'] = $latestChargeId;
            }

            $transfer = $this->stripe->transfers->create(
                $transferPayload,
                ['idempotency_key' => 'tr_'.$payout->id.$retryKey]
            );

            $payout->update([
                'stripe_transfer_id' => $transfer->id,
                'status'             => 'completed',
                'processed_at'       => now(),
                'failure_code'       => null,
                'failure_reason'     => null,
            ]);

            Log::info('Commission payout completed', [
                'payout_id'          => $payout->id,
                'gross_cents'        => $payout->gross_commission_cents,
                'wallet_debit_cents' => $walletDebitCents,
                'charge_cents'       => $chargeAmountCents,
                'platform_fee_cents' => $payout->platform_fee_cents,
                'net_cents'          => $payout->net_payout_cents,
                'funding_source'     => $payout->funding_source,
                'currency'           => $payout->currency_code,
            ]);

            // Rebuild commerce aggregates so commission_paid_cents is reflected.
            // Guard against null FKs (SET NULL on professional hard-delete).
            if ($payout->brand_professional_id && $payout->affiliate_professional_id) {
                \App\Jobs\Analytics\RebuildCommerceDailyAggregatesJob::dispatch(
                    (string) $payout->brand_professional_id,
                    (string) $payout->affiliate_professional_id,
                    now()->toDateString()
                );
            }

            return true;
        } catch (ApiErrorException $e) {
            // Transfer failed — credit wallet back, then attempt to auto-refund the card charge.
            if ($walletDebitCents > 0) {
                $this->creditBrandManualBalance($brand->id, $walletDebitCents, $currencyUpper);
            }

            $failureCode = 'transfer_failed';

            if ($payout->stripe_payment_intent_id && $chargeAmountCents > 0) {
                try {
                    $this->stripe->refunds->create([
                        'payment_intent' => $payout->stripe_payment_intent_id,
                    ]);
                    $failureCode = 'transfer_failed_refunded';
                    // Clear PI ID so an admin retry can create a fresh PaymentIntent
                    // (same idempotency key within 24h would return the now-refunded PI).
                    $payout->update(['stripe_payment_intent_id' => null]);
                } catch (\Throwable $refundEx) {
                    // Auto-refund failed — the brand may still be charged. Flag for manual resolution.
                    $failureCode = 'transfer_failed_refund_needed';
                    Log::error('Auto-refund after transfer failure failed — manual action required', [
                        'payout_id'         => $payout->id,
                        'payment_intent_id' => $payout->stripe_payment_intent_id,
                        'transfer_error'    => $e->getMessage(),
                        'refund_error'      => $refundEx->getMessage(),
                    ]);
                }
            }

            $this->failPayout($payout, $failureCode, $e->getMessage());

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
            'status'       => 'pending',
            'failure_code' => $code,
            'failure_reason' => $reason,
            'processed_at' => null,
        ]);

        Log::notice('Commission payout pending funding', [
            'payout_id' => $payout->id,
            'code'      => $code,
            'reason'    => $reason,
        ]);
    }

    private function failPayout(CommissionPayout $payout, string $code, string $reason): void
    {
        $payout->update([
            'status'         => 'failed',
            'failure_code'   => $code,
            'failure_reason' => $reason,
        ]);

        Log::warning('Commission payout failed', [
            'payout_id' => $payout->id,
            'code'      => $code,
            'reason'    => $reason,
        ]);
    }

    /**
     * Manually retry a stuck payout batch (admin endpoint).
     *
     * Increments retry_count so a fresh Stripe idempotency key is used —
     * prevents Stripe returning a refunded/failed PI from within the 24h TTL window.
     *
     * Blocked for transfer_failed_refund_needed: the auto-refund itself failed, so
     * the brand may still be charged. Manual verification is required before retrying.
     *
     * Runs synchronously so the admin sees the result immediately.
     */
    public function retryPayout(CommissionPayout $payout): bool
    {
        if (! in_array($payout->status, ['failed', 'pending'], true)) {
            return false;
        }

        // Auto-refund failed — manual verification required before retrying to avoid double-charging.
        if ($payout->failure_code === 'transfer_failed_refund_needed') {
            return false;
        }

        $payout->update([
            'status'         => 'pending',
            'failure_code'   => null,
            'failure_reason' => null,
            'retry_count'    => ($payout->retry_count ?? 0) + 1,
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
            'as_brand'     => $asBrand,
            'as_affiliate' => $asAffiliate,
        ];
    }
}
