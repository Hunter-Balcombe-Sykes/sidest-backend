<?php

use App\Models\Commerce\CommissionPayout;
use App\Models\Commerce\CommissionPayoutItem;
use App\Models\Commerce\Order;
use App\Services\Stripe\CommissionPayoutRefundService;
use Illuminate\Support\Facades\DB;

// Tests for CommissionPayoutRefundService::shrinkItem proportional math.
//
// When a pending payout's order is partially refunded, shrinkItem recalculates
// the order's commission from the remaining net (gross - refund) and adjusts the
// payout totals downward proportionally. The fee ratio stays constant.
//
// If the remaining net drives commission to zero (over-refund edge case), the
// payout is cancelled with failure_code='refunded_to_zero'.

beforeEach(function () {
    setupCommerceOrdersTables();

    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        payment_intent_id TEXT,
        charge_id TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_commission_cents INTEGER NOT NULL DEFAULT 0,
        platform_fee_cents INTEGER NOT NULL DEFAULT 0,
        net_payout_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        failure_reason TEXT,
        failure_code TEXT,
        failure_category TEXT,
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT,
        processed_at TEXT,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
        transfer_completed_at TEXT,
        last_retry_at TEXT,
        funding_failure_count INTEGER NOT NULL DEFAULT 0,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        grace_started_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY,
        payout_id TEXT,
        order_id TEXT,
        amount_cents INTEGER,
        created_at TEXT
    )');
});

function partialRefund_seedPayout(string $id, array $overrides = []): CommissionPayout
{
    return CommissionPayout::factory()->create(array_merge([
        'id' => $id,
        'status' => 'pending',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 300,
        'net_payout_cents' => 9700,
        'ledger_entry_count' => 1,
    ], $overrides));
}

function partialRefund_seedOrder(string $id, string $payoutId, int $grossCents, int $refundCents, int $commissionCents, int $commissionRate, string $status = 'partially_refunded'): Order
{
    return Order::factory()->create([
        'id' => $id,
        'payout_id' => $payoutId,
        'gross_cents' => $grossCents,
        'commission_cents' => $commissionCents,
        'commission_rate' => $commissionRate,
        'refund_cents' => $refundCents,
        'status' => $status,
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
    ]);
}

function partialRefund_seedItem(string $id, string $payoutId, string $orderId, int $amountCents): void
{
    CommissionPayoutItem::factory()->create([
        'id' => $id,
        'payout_id' => $payoutId,
        'order_id' => $orderId,
        'amount_cents' => $amountCents,
    ]);
}

it('shrinks commission proportionally when an order is partially refunded', function () {
    $payout = partialRefund_seedPayout('pr_p1', [
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 1000,
        'net_payout_cents' => 9000,
    ]);

    // Order: $100 gross, 10% commission = $10, $40 refunded → $60 remaining
    // New commission: $60 * 10% = $6 (600 cents)
    $order = partialRefund_seedOrder('pr_o1', 'pr_p1', 10000, 4000, 1000, 10, 'partially_refunded');
    partialRefund_seedItem('pri_1', 'pr_p1', 'pr_o1', 1000);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order, 4000);

    $freshPayout = $payout->fresh();
    $freshOrder = $order->fresh();

    // Commission shrunk from 1000 → 600 (60% of gross remains, 10% rate)
    expect((int) $freshOrder->commission_cents)->toBe(600);
    // Gross commission on payout reduced by delta (1000 - 600 = 400)
    expect((int) $freshPayout->gross_commission_cents)->toBe(9600);
});

it('maintains the fee ratio when shrinking', function () {
    // Fee ratio: 300 / 10000 = 0.03. After shrink by 4000 commission, new gross = 6000,
    // new fee = 6000 * 0.03 = 180.
    $payout = partialRefund_seedPayout('pr_p2', [
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 300,
        'net_payout_cents' => 9700,
    ]);

    $order = partialRefund_seedOrder('pr_o2', 'pr_p2', 10000, 5000, 1000, 10, 'partially_refunded');
    partialRefund_seedItem('pri_2', 'pr_p2', 'pr_o2', 1000);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order, 5000);

    $fresh = $payout->fresh();
    // New gross: 10000 - 500 = 9500 (commission delta: 1000 - 500 = 500)
    // New fee: 9500 * 0.03 = 285
    expect((int) $fresh->gross_commission_cents)->toBe(9500)
        ->and((int) $fresh->platform_fee_cents)->toBe(285)
        ->and((int) $fresh->net_payout_cents)->toBe(9215);
});

it('cancels payout when partial refund drives gross to zero', function () {
    $payout = partialRefund_seedPayout('pr_p3', [
        'gross_commission_cents' => 5000,
        'platform_fee_cents' => 500,
        'net_payout_cents' => 4500,
    ]);

    // Full over-refund: remaining net = 0, commission = 0
    $order = partialRefund_seedOrder('pr_o3', 'pr_p3', 5000, 5000, 5000, 100, 'partially_refunded');
    partialRefund_seedItem('pri_3', 'pr_p3', 'pr_o3', 5000);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order, 5000);

    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('cancelled')
        ->and($fresh->failure_code)->toBe('refunded_to_zero')
        ->and($fresh->failure_category)->toBe('order_refunded')
        ->and($fresh->processed_at)->not->toBeNull();
});

it('rounds down new commission from remaining net', function () {
    // Edge: non-integer commission after rounding. $35 gross, 15% rate, $12 refunded.
    // Remaining: $23 * 15% = $3.45 → rounds to 345 cents.
    $payout = partialRefund_seedPayout('pr_p4', [
        'gross_commission_cents' => 525, // 5000 * 0.15 = 750, trimmed by 225 delta later
        'platform_fee_cents' => 105,
        'net_payout_cents' => 420,
    ]);

    $order = partialRefund_seedOrder('pr_o4', 'pr_p4', 3500, 1200, 525, 15, 'partially_refunded');
    partialRefund_seedItem('pri_4', 'pr_p4', 'pr_o4', 525);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order, 1200);

    $freshOrder = $order->fresh();
    // Remaining net: 3500 - 1200 = 2300. Commission: round(2300 * 0.15) = 345
    expect((int) $freshOrder->commission_cents)->toBe(345);
});
