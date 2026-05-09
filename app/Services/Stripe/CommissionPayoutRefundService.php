<?php

namespace App\Services\Stripe;

use App\Models\Commerce\Order;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Handles order-refund events that arrive while a payout is still in-flight.
// Three outcomes depending on payout status:
//   pending / pending_funds  → recompute (partial) or remove item + cancel if empty
//   collecting / transferring → flag needs_manual_refund for ops
//   completed / failed / cancelled / reversed → no-op (clawback flow handles post-completion refunds)
class CommissionPayoutRefundService
{
    public function __construct(
        private readonly AnalyticsCacheService $analyticsCache
    ) {}

    public function handleOrderRefund(Order $order): void
    {
        if (! $order->payout_id) {
            return;
        }

        if (! in_array($order->status, ['refunded', 'partially_refunded'], true)) {
            return;
        }

        DB::transaction(function () use ($order): void {
            $payout = CommissionPayout::query()
                ->where('id', $order->payout_id)
                ->lockForUpdate()
                ->first();

            if (! $payout) {
                return;
            }

            if (in_array($payout->status, ['completed', 'failed', 'cancelled', 'reversed'], true)) {
                Log::info('payout.refund.terminal_state_skip', [
                    'order_id' => $order->id,
                    'payout_id' => $payout->id,
                    'status' => $payout->status,
                ]);

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
