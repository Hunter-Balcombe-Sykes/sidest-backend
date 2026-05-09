<?php

namespace App\Services\Stripe;

use App\Jobs\Stripe\ExecuteCommissionPayoutJob;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

// V2: Core. Processes eligible commission payouts with hybrid funding (wallet balance first, card charge for shortfall). Transfers net amount to affiliate via Stripe Connect (80/20 split).
class CommissionPayoutService
{
    private StripeClient $stripe;

    private NotificationPublisher $publisher;

    private AnalyticsCacheService $analyticsCache;

    private float $platformFeePercent;

    private int $systemHoldDays;

    private int $minHoldDays;

    private int $gracePeriodDays;

    public function __construct(?StripeClient $stripe = null, ?NotificationPublisher $publisher = null, ?AnalyticsCacheService $analyticsCache = null)
    {
        $this->stripe = $stripe ?? new StripeClient(array_filter([
            'api_key' => config('services.stripe.secret_key'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));
        $this->publisher = $publisher ?? app(NotificationPublisher::class);
        $this->analyticsCache = $analyticsCache ?? app(AnalyticsCacheService::class);
        $this->platformFeePercent = config('partna.store.platform_fee_percent', 3);
        $this->systemHoldDays = max(0, (int) config('partna.store.payout_hold_days', 7));
        $this->minHoldDays = (int) config('partna.store.min_payout_hold_days', 7);
        // Clamp to [1, 365] — values outside this range produce nonsensical void_at timestamps.
        $this->gracePeriodDays = max(1, min(365, (int) config('partna.store.grace_period_days', 60)));
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
            'batches_created' => 0,
            'batches_requeued' => 0,
        ];

        // Re-dispatch any in-flight batches. ExecuteCommissionPayoutJob's idempotent
        // resume logic handles collecting/transferring states safely.
        // Skip wallet_currency_mismatch payouts — they cannot proceed until the brand
        // resolves the mismatch manually. Re-dispatching them floods Horizon with no
        // forward progress; the sweep picks them up again once an admin clears the code.
        $existingPending = CommissionPayout::query()
            ->whereIn('status', ['pending', 'pending_funds', 'collecting', 'transferring'])
            ->whereNull('processed_at')
            ->where('eligible_after', '<=', now())
            ->where(function ($q) {
                $q->whereNull('failure_code')
                    ->orWhere('failure_code', '!=', 'wallet_currency_mismatch');
            })
            ->orderBy('eligible_after')
            ->limit(500)
            ->get();

        foreach ($existingPending as $pendingPayout) {
            ExecuteCommissionPayoutJob::dispatch($pendingPayout->id);
            $stats['batches_dispatched']++;
            $stats['batches_requeued']++;
        }

        // Only consider brands that have a card on file — brands without payment details
        // cannot be charged, so creating payout batches for them would immediately stall.
        $eligibleBrandIds = Professional::query()
            ->where('professional_type', 'brand')
            ->whereNotNull('stripe_customer_id')
            ->whereNotNull('stripe_payment_method_id')
            ->pluck('id');

        // Find all brands with unpaid approved orders, then apply per-brand hold days
        // to determine which orders have cleared their hold window.
        // Phase 3.5: source of truth moves from commission_movements to commerce.orders.
        // Exclude rate_source='pending' orders — these have an out-of-bounds metafield
        // and cannot be valued until the brand corrects their rate configuration.
        $brandIds = Order::query()
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->where('refund_cents', 0)
            ->where('rate_source', '!=', 'pending')
            ->whereIn('brand_professional_id', $eligibleBrandIds)
            ->distinct()
            ->pluck('brand_professional_id');

        // Pre-load brand store settings for hold-day lookups.
        $brandSettings = BrandStoreSettings::query()
            ->whereIn('professional_id', $brandIds)
            ->pluck('payout_hold_days', 'professional_id');

        foreach ($brandIds as $brandId) {
            $holdDays = $this->resolveHoldDays($brandSettings[$brandId] ?? null);
            $cutoff = now()->utc()->subDays($holdDays);

            $groups = Order::query()
                ->where('status', 'approved')
                ->whereNull('payout_id')
                ->where('refund_cents', 0)
                ->where('brand_professional_id', $brandId)
                ->where('occurred_at', '<=', $cutoff)
                ->select([
                    'brand_professional_id',
                    'affiliate_professional_id',
                    'currency_code',
                    DB::raw('SUM(commission_cents) as total_cents'),
                    DB::raw('COUNT(*) as entry_count'),
                ])
                ->groupBy('brand_professional_id', 'affiliate_professional_id', 'currency_code')
                ->having(DB::raw('SUM(commission_cents)'), '>', 0)
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
                        'brand_id' => $group->brand_professional_id,
                        'affiliate_id' => $group->affiliate_professional_id,
                        'currency' => $group->currency_code,
                        'error' => $e instanceof ApiErrorException ? ($e->getStripeCode() ?? 'stripe_error') : get_class($e),
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
     * Create a payout batch record and link all eligible orders.
     *
     * Phase 3.5+: reads from commerce.orders directly (not commission_movements).
     * Orders that are refunded after status='approved' have their status flipped to
     * 'partially_refunded'/'refunded', which the WHERE clause already excludes. Refunds
     * that arrive AFTER a payout is created are caught by revalidatePayoutOrders at the
     * start of processPayoutBatch before any financial operations run.
     */
    private function createPayoutBatch(
        string $brandId,
        string $affiliateId,
        string $currency,
        \DateTimeInterface $cutoff,
    ): ?CommissionPayout {
        // READ COMMITTED (PG default) — lockForUpdate() prevents a concurrent batch
        // from claiming the same orders between the SELECT and the payout_id stamp.
        return DB::transaction(function () use ($brandId, $affiliateId, $currency, $cutoff) {
            $orders = Order::query()
                ->where('status', 'approved')
                ->whereNull('payout_id')
                ->where('refund_cents', 0)
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('currency_code', $currency)
                ->where('occurred_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                return null;
            }

            $grossCents = (int) $orders->sum('commission_cents');
            if ($grossCents <= 0) {
                return null;
            }

            $platformFeeCents = (int) round($grossCents * $this->platformFeePercent / 100);
            $netPayoutCents = $grossCents - $platformFeeCents;

            if ($netPayoutCents <= 0) {
                return null;
            }

            // Per-payout grace window — 60d from creation. If the affiliate
            // hasn't activated Stripe Connect by then, VoidExpiredPayoutsJob
            // cancels this payout and clears the orders' payout_id stamps.
            // grace_period_days lives in config/partna.php so ops can tune
            // the policy without a code release.
            // ledger_entry_count now counts orders included; column rename deferred to Phase 4.
            $payout = CommissionPayout::forceCreate([
                'brand_professional_id' => $brandId,
                'affiliate_professional_id' => $affiliateId,
                'status' => 'pending',
                'gross_commission_cents' => $grossCents,
                'platform_fee_cents' => $platformFeeCents,
                'net_payout_cents' => $netPayoutCents,
                'currency_code' => strtoupper($currency),
                'ledger_entry_count' => $orders->count(),
                'eligible_after' => $cutoff,
                'void_at' => now()->addDays($this->gracePeriodDays),
                'funding_source' => null,
            ]);

            // Create one payout item per order; stamp orders.payout_id atomically
            // so the next sweep cannot double-claim these orders.
            foreach ($orders as $order) {
                CommissionPayoutItem::create([
                    'payout_id' => $payout->id,
                    'order_id' => $order->id,
                    'amount_cents' => $order->commission_cents,
                ]);
            }

            Order::whereIn('id', $orders->pluck('id')->all())
                ->update(['payout_id' => $payout->id]);

            return $payout;
        });
    }

    /**
     * Re-validate orders linked to a pending payout against the current DB state.
     *
     * Runs inside a lockForUpdate transaction so a concurrent refund webhook cannot
     * race this check. Stale orders (refund_cents > 0 or status != 'approved') are
     * released back to the next sweep. If enough valid orders remain the payout is
     * rebuilt in-place and the refreshed record is returned. If nothing valid remains
     * (or the rebuilt net is non-positive) the payout is cancelled and null is returned.
     */
    private function revalidatePayoutOrders(CommissionPayout $payout): ?CommissionPayout
    {
        return DB::transaction(function () use ($payout) {
            $orders = Order::query()
                ->where('payout_id', $payout->id)
                ->lockForUpdate()
                ->get();

            [$validOrders, $staleOrders] = $orders->partition(
                fn ($o) => $o->status === 'approved' && (int) $o->refund_cents === 0
            );

            // All orders still eligible — nothing to do.
            if ($staleOrders->isEmpty()) {
                return $payout;
            }

            // Release stale orders so the next sweep can re-evaluate them.
            $staleIds = $staleOrders->pluck('id')->all();
            Order::whereIn('id', $staleIds)->update(['payout_id' => null]);
            CommissionPayoutItem::where('payout_id', $payout->id)
                ->whereIn('order_id', $staleIds)
                ->delete();

            if ($validOrders->isEmpty()) {
                $payout->forceFill(['status' => 'cancelled', 'processed_at' => now()])->save();
                Log::notice('Commission payout cancelled: all linked orders became ineligible before processing', [
                    'payout_id' => $payout->id,
                    'stale_count' => count($staleIds),
                ]);

                return null;
            }

            // Rebuild batch totals from the remaining valid orders.
            $grossCents = (int) $validOrders->sum('commission_cents');
            $platformFeeCents = (int) round($grossCents * $this->platformFeePercent / 100);
            $netPayoutCents = $grossCents - $platformFeeCents;

            if ($netPayoutCents <= 0) {
                $validIds = $validOrders->pluck('id')->all();
                Order::whereIn('id', $validIds)->update(['payout_id' => null]);
                CommissionPayoutItem::where('payout_id', $payout->id)
                    ->whereIn('order_id', $validIds)
                    ->delete();
                $payout->forceFill(['status' => 'cancelled', 'processed_at' => now()])->save();
                Log::notice('Commission payout cancelled: net payout became non-positive after order revalidation', [
                    'payout_id' => $payout->id,
                    'gross_cents' => $grossCents,
                ]);

                return null;
            }

            $payout->forceFill([
                'gross_commission_cents' => $grossCents,
                'platform_fee_cents' => $platformFeeCents,
                'net_payout_cents' => $netPayoutCents,
                'ledger_entry_count' => $validOrders->count(),
            ])->save();

            Log::notice('Commission payout batch rebuilt after order revalidation', [
                'payout_id' => $payout->id,
                'stale_count' => count($staleIds),
                'remaining_count' => $validOrders->count(),
                'gross_cents' => $grossCents,
                'net_cents' => $netPayoutCents,
            ]);

            return $payout->fresh();
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

        // Re-validate orders before any financial operations. A refund webhook arriving
        // after createPayoutBatch committed can flip orders ineligible; catch that here
        // while we can still cancel cleanly rather than overpaying the affiliate.
        if ($payout->status === 'pending') {
            $payout = $this->revalidatePayoutOrders($payout);
            if ($payout === null) {
                return null; // Payout cancelled — all linked orders became ineligible.
            }
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

        // Idempotency key suffix used only for PaymentIntents — retry_count is incremented
        // on each admin manual retry so a fresh PI is created rather than returning a
        // refunded/failed one from within the 24-hour idempotency TTL window.
        // Transfers intentionally use a stable key (no suffix) so Stripe returns the same
        // transfer on any retry, preventing a duplicate payout if the transfer succeeded
        // but the HTTP response was lost before the ID could be recorded.
        $retryKey = '_r'.$payout->retry_count;

        // Step 1: Wallet debit — or resume from recorded state
        //
        // The debit and the status update are wrapped in a single DB::transaction
        // so a process crash between them can't leave the wallet reduced but the
        // payout record still showing 'pending' (which would cause a double-debit on retry).
        $walletDebitCents = 0;
        $chargeAmountCents = $amountToCollect;

        if (in_array($payout->status, ['collecting', 'collected', 'transferring'], true)) {
            // Guard: net_payout_cents should never reach 0 here via normal flow, but if
            // it does (e.g. after an order revalidation narrowed the batch to zero net),
            // abort rather than attempt a zero-value Stripe transfer.
            if ($payout->net_payout_cents <= 0) {
                $this->failPayout($payout, 'net_payout_zero', 'Net payout amount is zero after order revalidation.');

                return false;
            }

            // Wallet debit already committed in a previous run — read from DB, don't re-debit.
            $walletDebitCents = (int) ($payout->wallet_debit_cents ?? 0);
            $chargeAmountCents = (int) ($payout->charge_cents ?? $amountToCollect);
        } else {
            // READ COMMITTED (PG default) — the payout status update and wallet debit
            // are atomic here; lockForUpdate() inside debitBrandManualBalancePartial
            // guards against concurrent debits on the wallet row itself.
            $hasCurrencyMismatch = false;
            DB::transaction(function () use ($payout, $brand, $amountToCollect, $currencyUpper, &$walletDebitCents, &$chargeAmountCents, &$hasCurrencyMismatch): void {
                $walletDebitCents = $this->debitBrandManualBalancePartial($brand->id, $amountToCollect, $currencyUpper, $hasCurrencyMismatch);

                // Wallet has balance but in the wrong currency — abort without charging card.
                // markPendingFunding and notification happen outside the transaction below.
                if ($hasCurrencyMismatch) {
                    return;
                }

                $chargeAmountCents = $amountToCollect - $walletDebitCents;

                $fundingSource = 'card';
                if ($walletDebitCents > 0 && $chargeAmountCents > 0) {
                    $fundingSource = 'wallet_and_card';
                } elseif ($walletDebitCents > 0) {
                    $fundingSource = 'wallet';
                }

                $payout->forceFill([
                    'status' => 'collecting',
                    'funding_source' => $fundingSource,
                    'wallet_debit_cents' => $walletDebitCents,
                    'charge_cents' => $chargeAmountCents,
                    'failure_code' => null,
                    'failure_reason' => null,
                ])->save();
            });

            if ($hasCurrencyMismatch) {
                $walletCurrency = strtoupper((string) ($brand->stripe_manual_balance_currency ?? 'unknown'));
                $this->markPendingFunding(
                    $payout,
                    'wallet_currency_mismatch',
                    "Wallet balance is in {$walletCurrency} but payout requires {$currencyUpper}. Please contact support to resolve.",
                );

                $this->publisher->publish(
                    professionalId: $payout->brand_professional_id,
                    frontendType: 'Warning',
                    category: 'commissions',
                    title: 'Commission payout on hold',
                    body: sprintf(
                        'A commission payout of %s could not be processed because your wallet balance is in %s. Please contact support to resolve the currency mismatch.',
                        $this->formatMoney($payout->gross_commission_cents, $payout->currency_code),
                        $walletCurrency,
                    ),
                    dedupeKey: "wallet_currency_mismatch.{$payout->id}",
                    ctaUrl: '/account/settings?section=wallet',
                );

                return null;
            }
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
                ], ['idempotency_key' => 'pi_'.$payout->id.$retryKey]);

                if ($paymentIntent->status !== 'succeeded') {
                    // SCA required — cancel the PI so the idempotency key is freed for the next
                    // attempt. Without cancellation the same key returns this stuck PI for 24h.
                    try {
                        $this->stripe->paymentIntents->cancel($paymentIntent->id);
                    } catch (\Throwable) {
                        // Non-critical: PI may already be in a terminal state
                    }
                    if ($walletDebitCents > 0) {
                        $this->creditBrandManualBalance($brand->id, $walletDebitCents, $currencyUpper);
                    }
                    $this->markPendingFunding($payout, 'charge_requires_action', 'Card charge requires authentication.');

                    return null;
                }

                $latestChargeId = $this->extractLatestChargeId($paymentIntent);

                $payout->forceFill([
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'status' => 'collected',
                ])->save();
            } catch (ApiConnectionException|RateLimitException $e) {
                // Transient error — re-throw so Horizon retries with backoff.
                // Wallet debit is already committed in 'collecting' status; the
                // idempotent resume will skip re-debiting on the next attempt.
                throw $e;
            } catch (ApiErrorException $e) {
                // Terminal card failure (declined, invalid PM, SCA permanently blocked, etc.)
                if ($walletDebitCents > 0) {
                    $this->creditBrandManualBalance($brand->id, $walletDebitCents, $currencyUpper);
                }
                $this->markPendingFunding($payout, 'charge_failed', $e->getStripeCode() ?? 'stripe_error');

                return null;
            }
        } else {
            // Fully funded by wallet — only advance status if not already past this step
            if (! in_array($payout->status, ['collected', 'transferring'], true)) {
                $payout->forceFill(['status' => 'collected'])->save();
            }
        }

        // Step 3: Transfer net amount to the affiliate's Connect account
        try {
            if ($payout->status !== 'transferring') {
                $payout->forceFill(['status' => 'transferring'])->save();
            }

            // Guard: skip transfer creation if a prior run already recorded the ID
            // (e.g. the transfer was saved but the server crashed before marking complete).
            // $newTransfer is only set when we actually call Stripe in this run; the guard
            // path leaves it null and stays at 'transferring' for the webhook to complete.
            $newTransfer = null;
            if (! $payout->stripe_transfer_id) {
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

                $newTransfer = $this->stripe->transfers->create(
                    $transferPayload,
                    // Stable key — no retry_count suffix. Stripe returns the same transfer
                    // on any retry (including admin retries that increment retry_count),
                    // preventing a duplicate payout when the transfer succeeded but the
                    // response was lost before stripe_transfer_id could be recorded.
                    ['idempotency_key' => 'tr_'.$payout->id]
                );

                // Persist stripe_transfer_id before the completion record. If the process
                // crashes between these two saves, the guard above prevents re-creation.
                $payout->forceFill(['stripe_transfer_id' => $newTransfer->id])->save();
            }

            // Transfer.status='paid' means funds landed immediately (most cases).
            // 'pending' means the Connect account is not yet fully onboarded; the
            // transfer.paid webhook (A2.2) will flip status to completed once Stripe settles.
            // Guard/resume path ($newTransfer=null) stays at 'transferring' — the webhook
            // or ReconcileStuckTransferringPayoutsJob will complete it.
            $transferStatus = $newTransfer?->status ?? 'pending';

            if ($transferStatus === 'paid') {
                $payout->forceFill([
                    'status' => 'completed',
                    'processed_at' => now(),
                    'transfer_completed_at' => now(),
                    'failure_code' => null,
                    'failure_reason' => null,
                ])->save();

                $this->analyticsCache->bumpAnalyticsVersion($brand->id);
                $this->analyticsCache->bumpAnalyticsVersion($affiliate->id);

                Log::info('Commission payout completed (transfer paid immediately)', [
                    'payout_id' => $payout->id,
                    'gross_cents' => $payout->gross_commission_cents,
                    'wallet_debit_cents' => $walletDebitCents,
                    'charge_cents' => $chargeAmountCents,
                    'platform_fee_cents' => $payout->platform_fee_cents,
                    'net_cents' => $payout->net_payout_cents,
                    'funding_source' => $payout->funding_source,
                    'currency' => $payout->currency_code,
                ]);
            } else {
                // Leave status as 'transferring' — the transfer.paid webhook will complete it.
                Log::info('Commission payout transfer pending Stripe settlement', [
                    'payout_id' => $payout->id,
                    'stripe_transfer_id' => $payout->stripe_transfer_id,
                    'transfer_status' => $transferStatus,
                ]);
            }

            return true;
        } catch (ApiConnectionException|RateLimitException $e) {
            // Transient error — re-throw so Horizon retries from 'transferring' status.
            // Wallet and charge are NOT reversed. The stable transfer idempotency key
            // and the stripe_transfer_id guard above together ensure no duplicate transfer
            // is created if the original request succeeded but the response was lost.
            throw $e;
        } catch (ApiErrorException $e) {
            // Terminal transfer failure — credit wallet back, then attempt to auto-refund.
            if ($walletDebitCents > 0) {
                $this->creditBrandManualBalance($brand->id, $walletDebitCents, $currencyUpper);
            }

            $failureCode = 'transfer_failed';

            if ($payout->stripe_payment_intent_id && $chargeAmountCents > 0) {
                try {
                    $this->stripe->refunds->create([
                        'payment_intent' => $payout->stripe_payment_intent_id,
                    ], ['idempotency_key' => "rf_{$payout->id}_{$payout->stripe_payment_intent_id}"]);
                    $failureCode = 'transfer_failed_refunded';
                    // Clear PI ID so an admin retry can create a fresh PaymentIntent
                    // (same idempotency key within 24h would return the now-refunded PI).
                    $payout->forceFill(['stripe_payment_intent_id' => null])->save();
                } catch (\Throwable $refundEx) {
                    // Auto-refund failed — the brand may still be charged. Flag for manual resolution.
                    $failureCode = 'transfer_failed_refund_needed';
                    $payout->forceFill(['needs_manual_refund' => true])->save();
                    Log::error('Auto-refund after transfer failure failed — manual action required', [
                        'payout_id' => $payout->id,
                        'payment_intent_id' => $payout->stripe_payment_intent_id,
                        'transfer_error' => $e->getStripeCode() ?? 'stripe_error',
                        'refund_error' => $refundEx instanceof ApiErrorException ? ($refundEx->getStripeCode() ?? 'stripe_error') : get_class($refundEx),
                    ]);
                }
            }

            $this->failPayout($payout, $failureCode, $e->getStripeCode() ?? 'stripe_error');

            return false;
        }
    }

    /**
     * Debit the brand's wallet balance, up to the requested amount (partial OK).
     * Returns the actual amount debited (0 if no balance available).
     *
     * Sets $hasCurrencyMismatch=true when the wallet has a positive balance but in a
     * different currency than $currencyCode — caller must treat this as a hard stop,
     * not a zero-balance case, to avoid silently charging the full amount to the card.
     */
    private function debitBrandManualBalancePartial(string $brandId, int $requestedCents, string $currencyCode, bool &$hasCurrencyMismatch = false): int
    {
        if ($requestedCents <= 0) {
            return 0;
        }

        // READ COMMITTED (PG default) — lockForUpdate() on the professional row
        // serialises concurrent debits; no higher isolation level is required.
        return DB::transaction(function () use ($brandId, $requestedCents, $currencyCode, &$hasCurrencyMismatch) {
            $brand = Professional::query()
                ->whereKey($brandId)
                ->lockForUpdate()
                ->first();

            if (! $brand) {
                return 0;
            }

            $balance = max(0, (int) ($brand->stripe_manual_balance_cents ?? 0));
            $walletCurrency = strtoupper((string) ($brand->stripe_manual_balance_currency ?: $currencyCode));

            if ($walletCurrency !== strtoupper($currencyCode)) {
                // Only flag as a mismatch when there's actual balance in the wrong currency.
                // An empty wallet with any currency is just "no funds" — not a mismatch.
                if ($balance > 0) {
                    $hasCurrencyMismatch = true;
                }

                return 0;
            }

            if ($balance <= 0) {
                return 0;
            }

            $debit = min($balance, $requestedCents);
            $brand->stripe_manual_balance_cents = $balance - $debit;
            $brand->save();

            return $debit;
        });
    }

    public function creditBrandManualBalance(string $brandId, int $amountCents, string $currencyCode): void
    {
        if ($amountCents <= 0) {
            return;
        }

        // READ COMMITTED (PG default) — lockForUpdate() serialises concurrent credits.
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
        $payout->forceFill([
            'status'                => 'pending_funds',
            'failure_code'          => $code,
            'failure_reason'        => $reason,
            'processed_at'          => null,
            // Increment so RetryPendingFundsPayoutsJob can enforce the 7-attempt cap.
            'funding_failure_count' => ($payout->funding_failure_count ?? 0) + 1,
            // Schedule the next retry attempt. RetryPendingFundsPayoutsJob queries
            // WHERE next_retry_at <= now() — NULL never satisfies this in PostgreSQL.
            'next_retry_at'         => now()->addDay(),
            // Reset expiry each time we park the payout — without this, the void clock
            // counts from original creation while the payout loops in pending_funds,
            // causing VoidExpiredPayoutsJob to silently erase the affiliate's commission.
            'void_at'               => now()->addDays($this->gracePeriodDays),
        ])->save();

        Log::notice('Commission payout pending funding', [
            'payout_id' => $payout->id,
            'code' => $code,
            'reason' => $reason,
        ]);
    }

    private function failPayout(CommissionPayout $payout, string $code, string $reason): void
    {
        $payout->forceFill([
            'status' => 'failed',
            'failure_code' => $code,
            'failure_reason' => $reason,
        ])->save();

        Log::warning('Commission payout failed', [
            'payout_id' => $payout->id,
            'code' => $code,
            'reason' => $reason,
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
        if (! in_array($payout->status, ['failed', 'pending', 'pending_funds'], true)) {
            return false;
        }

        // Auto-refund failed — manual verification required before retrying to avoid double-charging.
        if ($payout->failure_code === 'transfer_failed_refund_needed') {
            return false;
        }

        // If the wallet was already debited in a previous run, resume from 'collecting'
        // so processPayoutBatch() enters its idempotent resume branch and skips
        // re-debiting. Resetting to 'pending' would bypass that branch and double-debit.
        $resumeStatus = ((int) ($payout->wallet_debit_cents ?? 0)) > 0 ? 'collecting' : 'pending';

        $payout->forceFill([
            'status' => $resumeStatus,
            'failure_code' => null,
            'failure_reason' => null,
            'needs_manual_refund' => false,
            'retry_count' => ($payout->retry_count ?? 0) + 1,
        ])->save();

        return $this->processPayoutBatch($payout) === true;
    }

    private function formatMoney(int $cents, string $currencyCode): string
    {
        $prefix = match (strtoupper($currencyCode)) {
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            'AUD' => 'A$',
            default => strtoupper($currencyCode).' ',
        };

        return $prefix.number_format($cents / 100, 2, '.', ',');
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
