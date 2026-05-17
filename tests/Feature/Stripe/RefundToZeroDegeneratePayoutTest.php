<?php

use App\Models\Commerce\CommissionPayout;
use App\Models\Commerce\CommissionPayoutItem;
use App\Models\Commerce\Order;
use App\Services\Stripe\CommissionPayoutRefundService;
use Illuminate\Support\Facades\DB;

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
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
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

it('cancels a pending payout when over-refund drives totals to zero', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'pending',
        'gross_commission_cents' => 5000,
        'platform_fee_cents' => 1000,
        'net_payout_cents' => 4000,
        'ledger_entry_count' => 1,
    ]);

    // Over-refund (refund_cents > gross_cents) forces shrinkItem to compute
    // commission=0 → newGross=0 → newNet=0 — without the refunded_to_zero guard,
    // the PI create would trip Stripe's amount_too_small.
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'gross_cents' => 5000,
        'commission_cents' => 5000,
        'commission_rate' => 100,
        'refund_cents' => 6000,
        'status' => 'partially_refunded',
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);

    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
    ]);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order, 6000);

    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('cancelled')
        ->and($fresh->failure_code)->toBe('refunded_to_zero')
        ->and($fresh->failure_category)->toBe('order_refunded')
        ->and($fresh->processed_at)->not->toBeNull()
        ->and((int) $fresh->gross_commission_cents)->toBe(0);
});
