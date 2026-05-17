<?php

use App\Models\Commerce\Order;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Stripe\CommissionPayoutRefundService;
use Illuminate\Support\Facades\Cache;
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
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT,
        processed_at TEXT,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
        transfer_completed_at TEXT,
        last_retry_at TEXT,
        failure_category TEXT,
        stripe_error_code TEXT,
        stripe_error_message TEXT,
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

// ─── Full refund — two orders in batch ──────────────────────────────────────

it('full refund of an order in a pending payout removes the item and shrinks gross_commission', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'pending',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 1000,
        'net_payout_cents' => 9000,
        'ledger_entry_count' => 2,
    ]);

    $orderA = Order::factory()->create([
        'payout_id' => $payout->id,
        'commission_cents' => 4000,
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);
    $orderB = Order::factory()->create([
        'payout_id' => $payout->id,
        'commission_cents' => 6000,
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);

    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $orderA->id,
        'amount_cents' => 4000,
    ]);
    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $orderB->id,
        'amount_cents' => 6000,
    ]);

    $orderA->forceFill(['status' => 'refunded', 'refund_cents' => $orderA->gross_cents])->save();

    app(CommissionPayoutRefundService::class)->handleOrderRefund($orderA);

    $payout->refresh();
    expect($payout->status)->toBe('pending');
    expect($payout->gross_commission_cents)->toBe(6000);
    expect($payout->ledger_entry_count)->toBe(1);
    expect($orderA->fresh()->payout_id)->toBeNull();
    expect(CommissionPayoutItem::where('order_id', $orderA->id)->count())->toBe(0);
});

// ─── Full refund — last item cancels payout ──────────────────────────────────

it('full refund of the last item cancels the payout', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'pending',
        'gross_commission_cents' => 5000,
        'platform_fee_cents' => 500,
        'net_payout_cents' => 4500,
        'ledger_entry_count' => 1,
    ]);
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'commission_cents' => 5000,
    ]);
    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
    ]);

    $order->forceFill(['status' => 'refunded', 'refund_cents' => $order->gross_cents])->save();
    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    $payout->refresh();
    expect($payout->status)->toBe('cancelled');
    expect($payout->failure_code)->toBe('refunded_within_grace');
});

// ─── Partial refund — shrinks item + recomputes payout totals ────────────────

it('partial refund recomputes gross_commission proportionally', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'pending',
        'gross_commission_cents' => 1000,
        'platform_fee_cents' => 100,
        'net_payout_cents' => 900,
    ]);
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'gross_cents' => 10000,
        'commission_cents' => 1000,
        'commission_rate' => 10.0,
    ]);
    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 1000,
    ]);

    $order->forceFill(['status' => 'partially_refunded', 'refund_cents' => 5000])->save();
    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    expect($payout->fresh()->gross_commission_cents)->toBe(500);
    expect(CommissionPayoutItem::where('order_id', $order->id)->first()->amount_cents)->toBe(500);
});

// ─── Terminal-state skip ─────────────────────────────────────────────────────

it('refund of order in a completed payout is a no-op', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'completed',
        'gross_commission_cents' => 5000,
    ]);
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'commission_cents' => 5000,
        'status' => 'refunded',
        'refund_cents' => 5000,
    ]);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    $payout->refresh();
    expect($payout->status)->toBe('completed');
    expect($payout->gross_commission_cents)->toBe(5000);
});

it('refund of order in a failed payout is a no-op', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'failed',
        'gross_commission_cents' => 5000,
    ]);
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'status' => 'refunded',
        'refund_cents' => 5000,
    ]);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    $payout->refresh();
    expect($payout->status)->toBe('failed');
});

// ─── Mid-flight flag ─────────────────────────────────────────────────────────

it('refund of order in processing sets needs_manual_refund', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'processing']);
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'status' => 'refunded',
        'refund_cents' => 10000,
    ]);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    expect($payout->fresh()->needs_manual_refund)->toBeTrue();
});

// ─── Cache invalidation: pending path clears both primary AND :stale twin ───

it('pending-path refund forgets both the affiliatePayoutState primary key and its :stale twin', function () {
    $payout = CommissionPayout::factory()->create([
        'status' => 'pending',
        'gross_commission_cents' => 5000,
        'ledger_entry_count' => 1,
    ]);
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'commission_cents' => 5000,
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);
    CommissionPayoutItem::factory()->create([
        'payout_id' => $payout->id,
        'order_id' => $order->id,
        'amount_cents' => 5000,
    ]);

    // Pre-seed both keys so we can confirm both get forgotten. CacheLockService writes
    // a `:stale` twin alongside the primary as part of its SWR contract — forgetting
    // only the primary leaves rememberLocked serving the stale copy.
    $primaryKey = CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id);
    $staleKey = $primaryKey.':stale';
    Cache::put($primaryKey, 'pre-refund-primary', 60);
    Cache::put($staleKey, 'pre-refund-stale', 600);

    $order->forceFill(['status' => 'refunded', 'refund_cents' => $order->gross_cents])->save();
    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    expect(Cache::get($primaryKey))->toBeNull();
    expect(Cache::get($staleKey))->toBeNull();
});

it('processing-path refund forgets both the affiliatePayoutState primary key and its :stale twin', function () {
    $payout = CommissionPayout::factory()->create(['status' => 'processing']);
    $order = Order::factory()->create([
        'payout_id' => $payout->id,
        'status' => 'refunded',
        'refund_cents' => 10000,
        'brand_professional_id' => $payout->brand_professional_id,
        'affiliate_professional_id' => $payout->affiliate_professional_id,
    ]);

    $primaryKey = CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id);
    $staleKey = $primaryKey.':stale';
    Cache::put($primaryKey, 'pre-refund-primary', 60);
    Cache::put($staleKey, 'pre-refund-stale', 600);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    // needs_manual_refund flip is visible on the brand's dashboard — must surface
    // without waiting for the analytics TTL.
    expect($payout->fresh()->needs_manual_refund)->toBeTrue();
    expect(Cache::get($primaryKey))->toBeNull();
    expect(Cache::get($staleKey))->toBeNull();
});

// ─── No-op when order has no payout ─────────────────────────────────────────

it('is a no-op when the order has no payout_id', function () {
    $order = Order::factory()->create([
        'payout_id' => null,
        'status' => 'refunded',
        'refund_cents' => 5000,
    ]);

    app(CommissionPayoutRefundService::class)->handleOrderRefund($order);

    expect($order->fresh()->payout_id)->toBeNull();
});
