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
        $this->holdDays = max(0, (int) config('comet.store.payout_hold_days', 0));
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
            ->leftJoin('retail.orders as o', 'o.id', '=', 'retail.commission_ledger_entries.order_id')
            ->whereNull('retail.commission_ledger_entries.payout_id')
            ->where('retail.commission_ledger_entries.entry_type', 'accrual')
            ->where('retail.commission_ledger_entries.status', 'approved')
            ->where('retail.commission_ledger_entries.occurred_at', '<=', $cutoff)
            ->select([
                'retail.commission_ledger_entries.brand_professional_id',
                'retail.commission_ledger_entries.affiliate_professional_id',
                'retail.commission_ledger_entries.currency_code',
                DB::raw("
                    CASE
                        WHEN lower(COALESCE(o.source, 'shopify')) = 'stripe_direct'
                            THEN 'stripe_sale_hold'
                        ELSE 'brand_charge'
                    END as funding_kind
                "),
                DB::raw('SUM(retail.commission_ledger_entries.amount_cents) as total_cents'),
                DB::raw('COUNT(*) as entry_count'),
            ])
            ->groupBy('retail.commission_ledger_entries.brand_professional_id', 'retail.commission_ledger_entries.affiliate_professional_id', 'retail.commission_ledger_entries.currency_code', DB::raw("
                CASE
                    WHEN lower(COALESCE(o.source, 'shopify')) = 'stripe_direct'
                        THEN 'stripe_sale_hold'
                    ELSE 'brand_charge'
                END
            "))
            ->having(DB::raw('SUM(retail.commission_ledger_entries.amount_cents)'), '>', 0)
            ->get();

        foreach ($groups as $group) {
            try {
                $payout = $this->createPayoutBatch(
                    $group->brand_professional_id,
                    $group->affiliate_professional_id,
                    $group->currency_code,
                    $group->funding_kind,
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
        string $fundingKind,
        \DateTimeInterface $cutoff,
    ): ?CommissionPayout {
        return DB::transaction(function () use ($brandId, $affiliateId, $currency, $fundingKind, $cutoff) {
            $entries = CommissionLedgerEntry::query()
                ->whereNull('payout_id')
                ->where('entry_type', 'accrual')
                ->where('status', 'approved')
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('currency_code', $currency)
                ->where('occurred_at', '<=', $cutoff)
                ->where(function ($query) use ($fundingKind): void {
                    $this->applyFundingKindFilter($query, $fundingKind);
                })
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
                ->where(function ($query) use ($fundingKind): void {
                    $this->applyFundingKindFilter($query, $fundingKind);
                })
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
                'funding_source' => $fundingKind === 'stripe_sale_hold' ? 'stripe_sale_hold' : null,
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
                ->where(function ($query) use ($fundingKind): void {
                    $this->applyFundingKindFilter($query, $fundingKind);
                })
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
     * Apply funding kind filter using a subquery instead of JOIN (avoids FOR UPDATE + LEFT JOIN conflict).
     */
    private function applyFundingKindFilter($query, string $fundingKind): void
    {
        if ($fundingKind === 'stripe_sale_hold') {
            $query->whereIn('order_id', function ($sub) {
                $sub->select('id')
                    ->from('retail.orders')
                    ->whereRaw("lower(source) = 'stripe_direct'");
            });
        } else {
            $query->where(function ($q) {
                $q->whereNull('order_id')
                  ->orWhereNotIn('order_id', function ($sub) {
                      $sub->select('id')
                          ->from('retail.orders')
                          ->whereRaw("lower(source) = 'stripe_direct'");
                  });
            });
        }
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
        $isPrefundedStripeSale = trim((string) ($payout->funding_source ?? '')) === 'stripe_sale_hold';

        if (! $affiliate?->stripe_connect_account_id || $affiliate->stripe_connect_status !== 'active') {
            $this->failPayout($payout, 'affiliate_not_connected', 'Affiliate Stripe Connect account is not active');
            return false;
        }

        if (! $brand) {
            $this->failPayout($payout, 'brand_missing', 'Brand account was not found.');
            return false;
        }

        if ($isPrefundedStripeSale) {
            return $this->completePrefundedPayout($payout, $brand, $affiliate);
        }

        // Card is required for all non-prefunded payouts.
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
                $paymentIntent = $this->stripe->paymentIntents->create([
                    'amount' => $chargeAmountCents,
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
                    ],
                ]);

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
                'wallet_debit_cents' => $walletDebitCents,
                'charge_cents' => $chargeAmountCents,
                'platform_fee_cents' => $payout->platform_fee_cents,
                'net_cents' => $payout->net_payout_cents,
                'funding_source' => $fundingSource,
                'currency' => $payout->currency_code,
            ]);

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

    private function completePrefundedPayout(
        CommissionPayout $payout,
        Professional $brand,
        Professional $affiliate,
    ): bool {
        $currencyLower = strtolower((string) $payout->currency_code);

        try {
            $payout->update([
                'status' => 'transferring',
                'charge_cents' => 0,
                'wallet_debit_cents' => 0,
                'failure_code' => null,
                'failure_reason' => null,
            ]);

            $transfer = $this->stripe->transfers->create([
                'amount' => $payout->net_payout_cents,
                'currency' => $currencyLower,
                'destination' => $affiliate->stripe_connect_account_id,
                'description' => "Commission payout #{$payout->id} to {$affiliate->display_name}",
                'metadata' => [
                    'comet_payout_id' => $payout->id,
                    'brand_id' => $brand->id,
                    'affiliate_id' => $affiliate->id,
                    'funding_source' => 'stripe_sale_hold',
                ],
            ]);

            $payout->update([
                'stripe_transfer_id' => $transfer->id,
                'status' => 'completed',
                'processed_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
            ]);

            Log::info('Commission payout completed from prefunded Stripe sale hold', [
                'payout_id' => $payout->id,
                'gross_cents' => $payout->gross_commission_cents,
                'platform_fee_cents' => $payout->platform_fee_cents,
                'net_cents' => $payout->net_payout_cents,
                'currency' => $payout->currency_code,
            ]);

            return true;
        } catch (ApiErrorException $e) {
            $this->failPayout($payout, 'prefunded_transfer_failed', $e->getMessage());
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
