<?php

namespace App\Services\Stripe;

use App\Models\Commerce\CommissionClawback;
use App\Models\Commerce\Order;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

// Handles order-refund events that arrive at various points in the payout lifecycle.
// Outcomes by payout status:
//   pending / pending_funds  → recompute (partial) or remove item + cancel if empty
//   collecting / transferring → flag needs_manual_refund for ops
//   completed                 → issue a Stripe Transfer Reversal to claw back the
//                               affiliate's share, record in commerce.commission_clawbacks
//   failed / cancelled / reversed → no-op (no money to recover)
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
     * @param  Order  $order  The order with its refund_cents already updated to the new cumulative total.
     * @param  int|null  $incrementalRefundCents  This refund event's subtotal in cents. Used for proportional
     *                                            clawback math when the payout is already completed. When null,
     *                                            derived from order.refund_cents minus the sum of prior clawbacks.
     * @param  string|null  $shopifyRefundId  Shopify refund.id for dedup at the clawback row level — partial
     *                                        unique on (payout_id, order_id, shopify_refund_id) catches duplicate
     *                                        events that slipped past upstream webhook idempotency.
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

            if (in_array($payout->status, ['failed', 'cancelled', 'reversed'], true)) {
                Log::info('payout.refund.terminal_state_skip', [
                    'order_id' => $order->id,
                    'payout_id' => $payout->id,
                    'status' => $payout->status,
                ]);

                return;
            }

            if ($payout->status === 'completed') {
                // Affiliate already paid — issue a Stripe Transfer Reversal for the
                // affiliate's share of this refund. Brand is refunding the customer
                // separately via Shopify; this recovers Partna's payout.
                $this->clawbackCompletedPayout($payout, $order, $incrementalRefundCents, $shopifyRefundId);

                return;
            }

            if (in_array($payout->status, ['collecting', 'transferring'], true)) {
                $payout->forceFill(['needs_manual_refund' => true])->save();
                Log::warning('payout.refund.mid_flight', [
                    'order_id' => $order->id,
                    'payout_id' => $payout->id,
                ]);

                return;
            }

            // pending / pending_funds
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
     * Issue a Stripe Transfer Reversal for the affiliate's share of a post-payout refund.
     *
     * For Partna's separate-charges-and-transfers model, Stripe's docs are explicit:
     * "refunding a charge doesn't affect any associated transfers. It's your platform's
     * responsibility to reconcile any amount owed back by reducing subsequent transfer
     * amounts or by reversing transfers." We use Transfer Reversals because Partna doesn't
     * use application_fee_amount (the platform fee is deducted by reducing the transfer
     * amount, not collected as a separate Stripe object).
     *
     * Clawback math: proportional to this refund's share of the order's gross.
     *   item_net      = item.amount_cents * (1 - platform_fee / payout.gross)
     *   refund_share  = incremental_refund_cents / order.gross_cents
     *   clawback_net  = item_net * refund_share
     *
     * Idempotency: the clawback row's partial-unique index on
     * (payout_id, order_id, shopify_refund_id) is the durable dedup; the Stripe call
     * uses the same composite as the idempotency key so a retry returns the original
     * reversal without re-applying.
     */
    private function clawbackCompletedPayout(
        CommissionPayout $payout,
        Order $order,
        ?int $incrementalRefundCents,
        ?string $shopifyRefundId,
    ): void {
        if (! $payout->stripe_transfer_id || $payout->gross_commission_cents <= 0) {
            Log::warning('payout.clawback.no_transfer_or_zero_gross', [
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

        // Derive the incremental refund amount from order.refund_cents minus prior
        // clawback amounts (matching this order's refund share, not total clawback cents)
        // when not provided explicitly.
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

        // Dedup: if this Shopify refund event has already produced a clawback row,
        // do nothing. The DB partial-unique index is the source of truth; this check
        // avoids the round-trip to Stripe when we already know it's a duplicate.
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

        // item_net = item.amount_cents minus its proportional share of platform fee
        $feeRatio = $payout->gross_commission_cents > 0
            ? $payout->platform_fee_cents / $payout->gross_commission_cents
            : 0.0;
        $itemNet = (int) round($item->amount_cents * (1 - $feeRatio));

        // Proportional clawback based on this refund's share of the order's gross
        $refundShare = min(1.0, $incrementalRefundCents / max(1, (int) $order->gross_cents));
        $clawbackCents = (int) round($itemNet * $refundShare);

        if ($clawbackCents <= 0) {
            return;
        }

        // Idempotency key: deterministic from (payout, order, shopify_refund_id). When
        // shopify_refund_id is unknown (manual flow), fall back to a UUID-stable hash of
        // (payout, order, cumulative_refund_cents) so the same financial state retries
        // safely but distinct refund events get distinct keys.
        $idempotencySalt = $shopifyRefundId ?? "cum:{$order->refund_cents}";
        $idempotencyKey = "rev_{$payout->id}_{$order->id}_".substr(md5($idempotencySalt), 0, 16);

        try {
            $reversal = $this->stripe->transfers->createReversal(
                $payout->stripe_transfer_id,
                [
                    'amount' => $clawbackCents,
                    'metadata' => [
                        'sidest_payout_id' => $payout->id,
                        'sidest_order_id' => $order->id,
                        'sidest_reason' => 'post_payout_refund',
                        'sidest_refund_share_cents' => $incrementalRefundCents,
                        'sidest_shopify_refund_id' => $shopifyRefundId ?? '',
                    ],
                ],
                ['idempotency_key' => $idempotencyKey],
            );

            $this->insertClawbackRow($payout, $order, $shopifyRefundId, [
                'stripe_reversal_id' => $reversal->id,
                'amount_cents' => $clawbackCents,
                'status' => 'reversed',
                'metadata' => [
                    'refund_share_cents' => $incrementalRefundCents,
                    'fee_ratio' => $feeRatio,
                ],
            ]);

            Log::notice('payout.clawback.reversed', [
                'payout_id' => $payout->id,
                'order_id' => $order->id,
                'reversal_id' => $reversal->id,
                'cents' => $clawbackCents,
            ]);
        } catch (ApiErrorException $e) {
            // Most common cause: insufficient connected-account balance. Flag for manual
            // recovery rather than retry — Partna ops can either wait for the affiliate's
            // balance to refill via future commissions or contact them directly.
            $payout->forceFill(['needs_manual_refund' => true])->save();

            $this->insertClawbackRow($payout, $order, $shopifyRefundId, [
                'amount_cents' => $clawbackCents,
                'status' => 'reversal_failed',
                'failure_reason' => $e->getStripeCode() ?? 'stripe_error',
                'metadata' => [
                    'refund_share_cents' => $incrementalRefundCents,
                    'fee_ratio' => $feeRatio,
                    'stripe_message' => $e->getMessage(),
                ],
            ]);

            Log::error('payout.clawback.reversal_failed', [
                'payout_id' => $payout->id,
                'order_id' => $order->id,
                'error' => $e->getStripeCode() ?? 'stripe_error',
            ]);
        }
    }

    /**
     * Insert a clawback row, swallowing the partial-unique constraint violation that
     * fires when the same Shopify refund event has already been processed. Acts as
     * the durable dedup layer beneath the in-memory exists() check.
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

        // If the shrink drove totals to zero/negative (over-refund edge case),
        // cancel the payout cleanly. Otherwise the transfer step would call
        // Stripe with amount=0 which rejects as amount_too_small and leaves
        // the payout in a stuck state.
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
     * commerce.orders, so shrinkItem() changes propagate automatically via the trigger.
     * removeItem() sets payout_id=NULL which the trigger currently ignores (no
     * payout-status columns in brand_affiliate_rollup today).
     *
     * Extend here — not inline — when payout-status columns are added to the rollup.
     * Use SELECT ... FOR UPDATE on the rollup row inside the same outer transaction.
     */
    private function adjustRollup(Order $order): void
    {
        // Intentional no-op. See method docblock.
    }
}
