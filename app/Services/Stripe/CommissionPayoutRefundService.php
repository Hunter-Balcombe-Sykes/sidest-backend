<?php

namespace App\Services\Stripe;

use App\Models\Commerce\CommissionClawback;
use App\Models\Commerce\Order;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Handles Shopify-side refunds that mutate a previously-batched commission payout.
 *
 * Outcomes by payout status:
 *   pending      → shrink (partial) or remove (full) the linked payout item; cancel payout if empty.
 *   processing   → flag needs_manual_refund; brand is notified, ops reconciles after PI settles.
 *   completed    → issue a single Stripe Refund with refund_application_fee + reverse_transfer.
 *                  Stripe proportionally reverses the affiliate transfer and the platform fee
 *                  atomically. If the affiliate balance can't cover the reverse_transfer, the
 *                  ENTIRE refund call fails — no partial state. We flag needs_manual_refund.
 *   failed / cancelled → no-op (no money moved).
 *
 * Replaces the v1-era transfers->createReversal chain. Under Option A there is no separate
 * transfer to reverse — application_fee_refund + transfer_reversal are flags on the single
 * Refund call.
 */
class CommissionPayoutRefundService
{
    private StripeClient $stripe;

    public function __construct(
        private readonly AnalyticsCacheService $analyticsCache,
        ?StripeClient $stripe = null,
    ) {
        $this->stripe = $stripe ?? new StripeClient(array_filter([
            'api_key' => config('services.stripe.secret_key'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));
    }

    /**
     * Process a refund event for an order, adjusting any linked payout.
     *
     * @param  Order  $order  The order with refund_cents already updated to the new cumulative total.
     * @param  int|null  $incrementalRefundCents  This refund event's subtotal in cents. Used for
     *                                            proportional clawback math when the payout is
     *                                            already completed. When null, derived from
     *                                            order.refund_cents minus the sum of prior clawbacks.
     * @param  string|null  $shopifyRefundId  Shopify refund.id for dedup at the clawback row level.
     */
    public function handleOrderRefund(
        Order $order,
        ?int $incrementalRefundCents = null,
        ?string $shopifyRefundId = null,
    ): void {
        if (! $order->payout_id) {
            return;
        }

        if (! in_array($order->status, ['refunded', 'partially_refunded'], true)) {
            return;
        }

        // SCALE-1: the completed-payout path issues a Stripe Refund. We MUST NOT
        // hold a FOR UPDATE row lock across that network call (under Stripe latency
        // two concurrent refund webhooks would pile up behind the lock and exhaust
        // the connection pool). The transaction below does only local work:
        // lock + decide + (for non-Stripe paths) mutate. For the completed-payout
        // case it returns a plan; the Stripe call + clawback-row write run via
        // DB::afterCommit so they fire AFTER the outermost transaction commits.
        $clawbackPlan = DB::transaction(function () use ($order, $incrementalRefundCents, $shopifyRefundId): ?array {
            $payout = CommissionPayout::query()
                ->where('id', $order->payout_id)
                ->lockForUpdate()
                ->first();

            if (! $payout) {
                return null;
            }

            if (in_array($payout->status, ['failed', 'cancelled'], true)) {
                Log::info('payout.refund.terminal_state_skip', [
                    'order_id' => $order->id,
                    'payout_id' => $payout->id,
                    'status' => $payout->status,
                ]);

                return null;
            }

            if ($payout->status === 'completed') {
                return $this->buildClawbackPlan($payout, $order, $incrementalRefundCents, $shopifyRefundId);
            }

            if ($payout->status === 'processing') {
                $this->flagMidFlight($payout, $order);
                // needs_manual_refund flip is visible on the brand's payout dashboard.
                // Bust caches so the warning surfaces without waiting for TTL.
                $this->bustPayoutCaches($order);

                return null;
            }

            // status === 'pending' — payout not yet sent to Stripe, just shrink/remove the item.
            if ($order->status === 'partially_refunded') {
                $this->shrinkItem($payout, $order);
            } else {
                $this->removeItem($payout, $order);
            }

            $this->adjustRollup($order);

            $this->bustPayoutCaches($order);

            return null;
        });

        if ($clawbackPlan === null) {
            return;
        }

        // afterCommit fires immediately when no transaction is open, and defers
        // to the outermost commit when this service is called inside a caller's
        // transaction — guaranteeing the Stripe HTTP call holds no row locks.
        DB::afterCommit(fn () => $this->executeClawback($clawbackPlan, $order));
    }

    /**
     * Forget every cache key that derives from an affiliate's payout state after
     * a refund-driven mutation. Bumps analytics version for both sides of the
     * payout (affiliate + brand) and clears both the primary and `:stale` SWR
     * twin of affiliatePayoutState — forgetting only the primary leaves
     * CacheLockService::rememberLocked serving the stale copy until TTL expires.
     */
    private function bustPayoutCaches(Order $order): void
    {
        $this->analyticsCache->bumpAnalyticsVersion($order->affiliate_professional_id);
        $this->analyticsCache->bumpAnalyticsVersion($order->brand_professional_id);

        $stateKey = CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id);
        Cache::forget($stateKey);
        Cache::forget($stateKey.':stale');
    }

    /**
     * Flag an in-flight (processing) payout for manual review. The PaymentIntent has been
     * accepted by Stripe but we're awaiting payment_intent.succeeded — we can't safely
     * mutate amounts now. Ops reconciles after the PI completes.
     */
    private function flagMidFlight(CommissionPayout $payout, Order $order): void
    {
        $wasFlagged = (bool) $payout->needs_manual_refund;
        $payout->forceFill(['needs_manual_refund' => true])->save();

        if (! $wasFlagged) {
            try {
                app(NotificationPublisher::class)->publish(
                    professionalId: (string) $payout->brand_professional_id,
                    frontendType: 'Warning',
                    category: 'commissions',
                    title: 'Refund flagged for manual review',
                    body: 'A refund arrived while a commission payout was being processed. Our team will reconcile the amounts and follow up.',
                    dedupeKey: "needs_manual_refund.{$payout->id}.{$order->id}",
                    ctaUrl: '/account/commerce/payouts',
                    retentionConfigKey: 'payout',
                );
            } catch (\Throwable $notifyEx) {
                Log::warning('payout.refund.mid_flight_notify_failed', [
                    'payout_id' => $payout->id,
                    'order_id' => $order->id,
                    'error' => $notifyEx->getMessage(),
                ]);
            }
        }

        Log::error('payout.refund.mid_flight', [
            'order_id' => $order->id,
            'payout_id' => $payout->id,
            'status' => $payout->status,
        ]);
    }

    /**
     * Phase 1 (synchronous, under lock): validate the completed-payout refund and
     * compute the clawback amount + idempotency key. Returns a plan dict consumed
     * by executeClawback after the surrounding transaction commits, or null when
     * the refund should be a no-op (missing PI, missing item, zero share, dedup hit).
     *
     * No Stripe I/O happens here — the caller holds the payout row lock for the
     * duration of this call, so it must remain purely local work.
     *
     * @return array{
     *   payout_id: string,
     *   order_id: string,
     *   currency_code: string,
     *   payment_intent_id: string,
     *   shopify_refund_id: ?string,
     *   incremental_refund_cents: int,
     *   refund_cents: int,
     *   is_partial: bool,
     *   idempotency_key: string,
     *   fee_ratio: float
     * }|null
     */
    private function buildClawbackPlan(
        CommissionPayout $payout,
        Order $order,
        ?int $incrementalRefundCents,
        ?string $shopifyRefundId,
    ): ?array {
        if (! $payout->payment_intent_id || $payout->gross_commission_cents <= 0) {
            Log::warning('payout.clawback.no_pi_or_zero_gross', [
                'payout_id' => $payout->id,
                'order_id' => $order->id,
            ]);

            return null;
        }

        $item = CommissionPayoutItem::where('payout_id', $payout->id)
            ->where('order_id', $order->id)
            ->first();

        if (! $item) {
            Log::warning('payout.clawback.no_payout_item', [
                'payout_id' => $payout->id,
                'order_id' => $order->id,
            ]);

            return null;
        }

        if ($incrementalRefundCents === null) {
            $priorRefundCovered = (int) CommissionClawback::query()
                ->where('payout_id', $payout->id)
                ->where('order_id', $order->id)
                ->get()
                ->sum(fn ($c) => (int) ($c->metadata['refund_share_cents'] ?? 0));
            $incrementalRefundCents = max(0, (int) $order->refund_cents - $priorRefundCovered);
        }

        if ($incrementalRefundCents <= 0 || $order->gross_cents <= 0) {
            return null;
        }

        // Dedup pre-check — the DB partial-unique index on (payout_id, order_id, shopify_refund_id)
        // is the source of truth; this avoids the Stripe round-trip on duplicate events.
        if ($shopifyRefundId !== null) {
            $exists = CommissionClawback::query()
                ->where('payout_id', $payout->id)
                ->where('order_id', $order->id)
                ->where('shopify_refund_id', $shopifyRefundId)
                ->exists();

            if ($exists) {
                Log::info('payout.clawback.duplicate_event_skipped', [
                    'payout_id' => $payout->id,
                    'order_id' => $order->id,
                    'shopify_refund_id' => $shopifyRefundId,
                ]);

                return null;
            }
        }

        // The refund amount tracks the buyer-side refund: this refund event's share of the order's
        // gross times the brand-charged commission. Stripe reverses fee + transfer proportionally
        // so we don't compute those ratios here — we record the requested amount and what Stripe
        // confirms in the response.
        $refundShare = min(1.0, $incrementalRefundCents / max(1, (int) $order->gross_cents));
        $refundCents = (int) round($item->amount_cents * $refundShare);
        $isPartial = $incrementalRefundCents < (int) $order->gross_cents;

        if ($refundCents <= 0) {
            return null;
        }

        // Deterministic idempotency key — retries return the original Refund. When shopify_refund_id
        // is unknown (manual recovery flow), salt with cumulative refund_cents so distinct refund
        // events get distinct keys.
        $idempotencySalt = $shopifyRefundId ?? "cum:{$order->refund_cents}";
        $idempotencyKey = "rf_{$payout->id}_{$order->id}_".substr(md5($idempotencySalt), 0, 16);

        $feeRatio = $payout->gross_commission_cents > 0
            ? $payout->platform_fee_cents / $payout->gross_commission_cents
            : 0.0;

        return [
            'payout_id' => (string) $payout->id,
            'order_id' => (string) $order->id,
            'currency_code' => strtoupper((string) $payout->currency_code),
            'payment_intent_id' => (string) $payout->payment_intent_id,
            'shopify_refund_id' => $shopifyRefundId,
            'incremental_refund_cents' => (int) $incrementalRefundCents,
            'refund_cents' => $refundCents,
            'is_partial' => $isPartial,
            'idempotency_key' => $idempotencyKey,
            'fee_ratio' => $feeRatio,
        ];
    }

    /**
     * Phase 2 + 3: issue the Stripe Refund and persist the clawback row. Runs via
     * DB::afterCommit so no DB lock is held across the network call. The follow-up
     * write happens in its own short transaction.
     *
     * Under Option A the original commission charge is a destination charge on the
     * PLATFORM, so the refund is platform-scoped (no stripe_account header).
     * refund_application_fee + reverse_transfer are both true — Stripe atomically
     * reverses the platform fee AND the affiliate transfer. If the affiliate balance
     * can't cover the proportional reverse_transfer, Stripe rejects the ENTIRE call
     * — no half-applied state — and we flag needs_manual_refund.
     *
     * @param  array{
     *   payout_id: string,
     *   order_id: string,
     *   currency_code: string,
     *   payment_intent_id: string,
     *   shopify_refund_id: ?string,
     *   incremental_refund_cents: int,
     *   refund_cents: int,
     *   is_partial: bool,
     *   idempotency_key: string,
     *   fee_ratio: float
     * }  $plan
     * @param  Order  $order  Carries affiliate/brand professional ids for cache busting
     *                        after the clawback row is written (success) or
     *                        needs_manual_refund is flipped (failure).
     */
    private function executeClawback(array $plan, Order $order): void
    {
        try {
            $refund = $this->stripe->refunds->create([
                'payment_intent' => $plan['payment_intent_id'],
                'amount' => $plan['refund_cents'],
                'refund_application_fee' => true,
                'reverse_transfer' => true,
                'metadata' => [
                    'sidest_payout_id' => $plan['payout_id'],
                    'sidest_order_id' => $plan['order_id'],
                    'sidest_reason' => 'post_payout_refund',
                    'sidest_refund_share_cents' => $plan['incremental_refund_cents'],
                    'sidest_shopify_refund_id' => $plan['shopify_refund_id'] ?? '',
                ],
            ], [
                'idempotency_key' => $plan['idempotency_key'],
            ]);

            $feeRefundCents = (int) round($plan['refund_cents'] * $plan['fee_ratio']);
            $transferReversalCents = $plan['refund_cents'] - $feeRefundCents;

            DB::transaction(function () use ($plan, $refund, $feeRefundCents, $transferReversalCents): void {
                $this->insertClawbackRow($plan, [
                    'refund_id' => $refund->id,
                    'stripe_reversal_id' => $refund->id, // legacy column kept populated for back-compat queries
                    'refund_amount_cents' => $plan['refund_cents'],
                    'application_fee_refund_cents' => $feeRefundCents,
                    'transfer_reversal_cents' => $transferReversalCents,
                    'amount_cents' => $transferReversalCents, // legacy column = affiliate-side reversal
                    'is_partial' => $plan['is_partial'],
                    'needs_manual_refund' => false,
                    'status' => 'reversed',
                    'metadata' => [
                        'refund_share_cents' => $plan['incremental_refund_cents'],
                        'fee_ratio' => $plan['fee_ratio'],
                    ],
                ]);
            });

            $this->bustPayoutCaches($order);

            Log::notice('payout.clawback.refunded', [
                'payout_id' => $plan['payout_id'],
                'order_id' => $plan['order_id'],
                'refund_id' => $refund->id,
                'refund_cents' => $plan['refund_cents'],
                'fee_refund_cents' => $feeRefundCents,
                'transfer_reversal_cents' => $transferReversalCents,
            ]);
        } catch (ApiErrorException $e) {
            // Affiliate-balance-insufficient is the typical cause; Stripe rejected the
            // call atomically so nothing moved. Flag for manual recovery and record the
            // attempt — ops contacts the affiliate or waits for the balance to refill.
            DB::transaction(function () use ($plan, $e): void {
                $payout = CommissionPayout::query()->where('id', $plan['payout_id'])->first();
                if ($payout) {
                    $payout->forceFill(['needs_manual_refund' => true])->save();
                }

                $this->insertClawbackRow($plan, [
                    'refund_amount_cents' => $plan['refund_cents'],
                    'amount_cents' => 0,
                    'is_partial' => $plan['is_partial'],
                    'needs_manual_refund' => true,
                    'status' => 'reversal_failed',
                    'failure_reason' => $e->getStripeCode() ?? 'stripe_error',
                    'metadata' => [
                        'refund_share_cents' => $plan['incremental_refund_cents'],
                        'stripe_message' => $e->getMessage(),
                    ],
                ]);
            });

            $this->bustPayoutCaches($order);

            Log::error('payout.clawback.refund_failed', [
                'payout_id' => $plan['payout_id'],
                'order_id' => $plan['order_id'],
                'error' => $e->getStripeCode() ?? 'stripe_error',
            ]);
        }
    }

    /**
     * Insert a clawback row, swallowing the partial-unique constraint violation that
     * fires when the same Shopify refund event has already been processed. Acts as the
     * durable dedup layer beneath the in-memory exists() check.
     *
     * @param  array{
     *   payout_id: string,
     *   order_id: string,
     *   currency_code: string,
     *   shopify_refund_id: ?string,
     *   ...
     * }  $plan
     * @param  array<string, mixed>  $payload
     */
    private function insertClawbackRow(array $plan, array $payload): void
    {
        $row = array_merge([
            'payout_id' => $plan['payout_id'],
            'order_id' => $plan['order_id'],
            'shopify_refund_id' => $plan['shopify_refund_id'],
            'currency_code' => $plan['currency_code'],
        ], $payload);

        try {
            (new CommissionClawback)->forceFill($row)->save();
        } catch (UniqueConstraintViolationException) {
            Log::info('payout.clawback.duplicate_insert_swallowed', [
                'payout_id' => $plan['payout_id'],
                'order_id' => $plan['order_id'],
                'shopify_refund_id' => $plan['shopify_refund_id'],
            ]);
        }
    }

    private function shrinkItem(CommissionPayout $payout, Order $order): void
    {
        $remainingNet = max(0, $order->gross_cents - $order->refund_cents);
        $newCommission = (int) round($remainingNet * ($order->commission_rate / 100.0));

        $item = CommissionPayoutItem::where('payout_id', $payout->id)
            ->where('order_id', $order->id)
            ->first();

        if (! $item) {
            return;
        }

        $delta = $item->amount_cents - $newCommission;

        $item->forceFill(['amount_cents' => $newCommission])->save();
        $order->forceFill(['commission_cents' => $newCommission])->save();

        $newGross = max(0, $payout->gross_commission_cents - $delta);
        $feeRatio = $payout->gross_commission_cents > 0
            ? $payout->platform_fee_cents / $payout->gross_commission_cents
            : 0.0;
        $newFee = (int) round($newGross * $feeRatio);
        $newNet = $newGross - $newFee;

        $payout->forceFill([
            'gross_commission_cents' => $newGross,
            'platform_fee_cents' => $newFee,
            'net_payout_cents' => $newNet,
        ])->save();

        // Over-refund edge: shrink drove totals to zero. Cancel cleanly so the next PI create
        // doesn't trip Stripe's amount_too_small.
        if ($newNet <= 0 || $newGross <= 0) {
            $payout->forceFill([
                'status' => 'cancelled',
                'failure_code' => 'refunded_to_zero',
                'failure_reason' => 'All commission refunded — payout cancelled',
                'failure_category' => 'order_refunded',
                'processed_at' => now(),
            ])->save();
        }
    }

    private function removeItem(CommissionPayout $payout, Order $order): void
    {
        $item = CommissionPayoutItem::where('payout_id', $payout->id)
            ->where('order_id', $order->id)
            ->first();

        if ($item) {
            $item->delete();
        }

        $order->forceFill(['payout_id' => null])->save();

        $remainingCount = CommissionPayoutItem::where('payout_id', $payout->id)->count();

        if ($remainingCount === 0) {
            $payout->forceFill([
                'status' => 'cancelled',
                'failure_code' => 'refunded_within_grace',
                'failure_reason' => 'All orders refunded before payout completed',
                'failure_category' => 'order_refunded',
                'processed_at' => now(),
            ])->save();

            return;
        }

        $newGross = (int) CommissionPayoutItem::where('payout_id', $payout->id)->sum('amount_cents');
        $feeRatio = $payout->gross_commission_cents > 0
            ? $payout->platform_fee_cents / $payout->gross_commission_cents
            : 0.0;
        $newFee = (int) round($newGross * $feeRatio);
        $newNet = $newGross - $newFee;

        $payout->forceFill([
            'gross_commission_cents' => $newGross,
            'platform_fee_cents' => $newFee,
            'net_payout_cents' => $newNet,
            'ledger_entry_count' => $remainingCount,
        ])->save();
    }

    /**
     * Extension point for rollup reconciliation after a refund-driven payout mutation.
     *
     * The orders trigger (trg_rollup → rollup_apply_delta) fires on every UPDATE to
     * commerce.orders, so shrinkItem() changes propagate automatically. removeItem()
     * sets payout_id=NULL which the trigger currently ignores. Extend here — not inline
     * — when payout-status columns are added to brand_affiliate_rollup.
     */
    private function adjustRollup(Order $order): void
    {
        // Intentional no-op. See method docblock.
    }
}
