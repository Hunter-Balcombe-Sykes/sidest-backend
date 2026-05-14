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

        DB::transaction(function () use ($order, $incrementalRefundCents, $shopifyRefundId): void {
            $payout = CommissionPayout::query()
                ->where('id', $order->payout_id)
                ->lockForUpdate()
                ->first();

            if (! $payout) {
                return;
            }

            if (in_array($payout->status, ['failed', 'cancelled'], true)) {
                Log::info('payout.refund.terminal_state_skip', [
                    'order_id' => $order->id,
                    'payout_id' => $payout->id,
                    'status' => $payout->status,
                ]);

                return;
            }

            if ($payout->status === 'completed') {
                $this->clawbackCompletedPayout($payout, $order, $incrementalRefundCents, $shopifyRefundId);

                return;
            }

            if ($payout->status === 'processing') {
                $this->flagMidFlight($payout, $order);

                return;
            }

            // status === 'pending' — payout not yet sent to Stripe, just shrink/remove the item.
            if ($order->status === 'partially_refunded') {
                $this->shrinkItem($payout, $order);
            } else {
                $this->removeItem($payout, $order);
            }

            $this->adjustRollup($order);

            $this->analyticsCache->bumpAnalyticsVersion($order->affiliate_professional_id);
            $this->analyticsCache->bumpAnalyticsVersion($order->brand_professional_id);
            Cache::forget(CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id));
        });
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
     * Issue a single atomic Stripe Refund for the affiliate's share of a post-payout refund.
     *
     * Under Option A the original commission charge is a destination charge on the PLATFORM,
     * so the refund runs platform-scoped (no stripe_account header). refund_application_fee
     * and reverse_transfer are both true — Stripe proportionally reverses:
     *   - the application fee from the platform balance
     *   - the auto-transfer from the affiliate's balance
     * in one atomic call. If the affiliate balance can't cover the proportional reversal,
     * Stripe rejects the ENTIRE refund — no half-applied state. We flag needs_manual_refund.
     *
     * The refund amount is the brand's customer-facing refund (item.amount_cents proportional
     * to this refund's share of the order's gross). Stripe handles the fee/transfer ratios
     * internally — we don't compute them, we just store what we requested + the refund_id.
     */
    private function clawbackCompletedPayout(
        CommissionPayout $payout,
        Order $order,
        ?int $incrementalRefundCents,
        ?string $shopifyRefundId,
    ): void {
        if (! $payout->payment_intent_id || $payout->gross_commission_cents <= 0) {
            Log::warning('payout.clawback.no_pi_or_zero_gross', [
                'payout_id' => $payout->id,
                'order_id' => $order->id,
            ]);

            return;
        }

        $item = CommissionPayoutItem::where('payout_id', $payout->id)
            ->where('order_id', $order->id)
            ->first();

        if (! $item) {
            Log::warning('payout.clawback.no_payout_item', [
                'payout_id' => $payout->id,
                'order_id' => $order->id,
            ]);

            return;
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
            return;
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

                return;
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
            return;
        }

        // Deterministic idempotency key — retries return the original Refund. When shopify_refund_id
        // is unknown (manual recovery flow), salt with cumulative refund_cents so distinct refund
        // events get distinct keys.
        $idempotencySalt = $shopifyRefundId ?? "cum:{$order->refund_cents}";
        $idempotencyKey = "rf_{$payout->id}_{$order->id}_".substr(md5($idempotencySalt), 0, 16);

        try {
            $refund = $this->stripe->refunds->create([
                'payment_intent' => $payout->payment_intent_id,
                'amount' => $refundCents,
                'refund_application_fee' => true,
                'reverse_transfer' => true,
                'metadata' => [
                    'sidest_payout_id' => $payout->id,
                    'sidest_order_id' => $order->id,
                    'sidest_reason' => 'post_payout_refund',
                    'sidest_refund_share_cents' => $incrementalRefundCents,
                    'sidest_shopify_refund_id' => $shopifyRefundId ?? '',
                ],
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            // Stripe reverses fee + transfer proportionally to the refund amount. We compute and
            // store the expected splits using the payout's fee ratio for reconciliation visibility
            // — these match what Stripe actually reversed, modulo rounding.
            $feeRatio = $payout->gross_commission_cents > 0
                ? $payout->platform_fee_cents / $payout->gross_commission_cents
                : 0.0;
            $feeRefundCents = (int) round($refundCents * $feeRatio);
            $transferReversalCents = $refundCents - $feeRefundCents;

            $this->insertClawbackRow($payout, $order, $shopifyRefundId, [
                'refund_id' => $refund->id,
                'stripe_reversal_id' => $refund->id, // populate legacy column for backward-compat queries
                'refund_amount_cents' => $refundCents,
                'application_fee_refund_cents' => $feeRefundCents,
                'transfer_reversal_cents' => $transferReversalCents,
                'amount_cents' => $transferReversalCents, // legacy column = the affiliate-side reversal
                'is_partial' => $isPartial,
                'needs_manual_refund' => false,
                'status' => 'reversed',
                'metadata' => [
                    'refund_share_cents' => $incrementalRefundCents,
                    'fee_ratio' => $feeRatio,
                ],
            ]);

            Log::notice('payout.clawback.refunded', [
                'payout_id' => $payout->id,
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'refund_cents' => $refundCents,
                'fee_refund_cents' => $feeRefundCents,
                'transfer_reversal_cents' => $transferReversalCents,
            ]);
        } catch (ApiErrorException $e) {
            // Most common cause: affiliate balance insufficient for proportional reverse_transfer.
            // Stripe rejects the whole call atomically — nothing happened. We flag for manual
            // recovery; ops contacts the affiliate or waits for their balance to refill via
            // future commissions.
            $payout->forceFill(['needs_manual_refund' => true])->save();

            $this->insertClawbackRow($payout, $order, $shopifyRefundId, [
                'refund_amount_cents' => $refundCents,
                'amount_cents' => 0,
                'is_partial' => $isPartial,
                'needs_manual_refund' => true,
                'status' => 'reversal_failed',
                'failure_reason' => $e->getStripeCode() ?? 'stripe_error',
                'metadata' => [
                    'refund_share_cents' => $incrementalRefundCents,
                    'stripe_message' => $e->getMessage(),
                ],
            ]);

            Log::error('payout.clawback.refund_failed', [
                'payout_id' => $payout->id,
                'order_id' => $order->id,
                'error' => $e->getStripeCode() ?? 'stripe_error',
            ]);
        }
    }

    /**
     * Insert a clawback row, swallowing the partial-unique constraint violation that
     * fires when the same Shopify refund event has already been processed. Acts as the
     * durable dedup layer beneath the in-memory exists() check.
     *
     * @param  array<string, mixed>  $payload
     */
    private function insertClawbackRow(
        CommissionPayout $payout,
        Order $order,
        ?string $shopifyRefundId,
        array $payload,
    ): void {
        $row = array_merge([
            'payout_id' => $payout->id,
            'order_id' => $order->id,
            'shopify_refund_id' => $shopifyRefundId,
            'currency_code' => strtoupper((string) $payout->currency_code),
        ], $payload);

        try {
            (new CommissionClawback)->forceFill($row)->save();
        } catch (UniqueConstraintViolationException) {
            Log::info('payout.clawback.duplicate_insert_swallowed', [
                'payout_id' => $payout->id,
                'order_id' => $order->id,
                'shopify_refund_id' => $shopifyRefundId,
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
