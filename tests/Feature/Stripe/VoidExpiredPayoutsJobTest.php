<?php

use App\Jobs\Stripe\VoidExpiredPayoutsJob;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderEvent;
use App\Models\Retail\CommissionPayout;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Facades\DB;

// Tests for the nightly cron that voids commission payouts whose 60-day grace
// window has expired without the affiliate connecting Stripe Connect.
// Closes #CR-003 — UI promised 60-day grace but no enforcement existed.
//
// Phase 4+: linked commerce.orders rows are voided (status='voided') instead of
// legacy commission_movements. The voidOrder() optimistic guard uses the
// payout_id stamp to scope the update to exactly the orders attached to the
// just-cancelled payout.

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow(null);
});

beforeEach(function () {
    setupProfessionalsTable();
    setupCommerceOrdersTables();

    $conn = DB::connection('pgsql');

    foreach ([
        'stripe_connect_account_id TEXT',
        "stripe_connect_status TEXT DEFAULT 'not_connected'",
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
            // already added by another helper
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        stripe_payment_intent_id TEXT,
        stripe_transfer_id TEXT,
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
        funding_source TEXT,
        wallet_debit_cents INTEGER DEFAULT 0,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
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

function expiredPayout_seedAffiliate(string $id, string $stripeStatus = 'not_connected'): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "affiliate-{$id}",
        'handle_lc' => "affiliate-{$id}",
        'display_name' => "Affiliate {$id}",
        'professional_type' => 'influencer',
        'status' => 'active',
        'stripe_connect_status' => $stripeStatus,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function expiredPayout_seedBrand(string $id): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => "Brand {$id}",
        'professional_type' => 'brand',
        'status' => 'active',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function expiredPayout_seedPayout(string $id, string $voidAt, string $status = 'pending', array $overrides = []): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
        'status' => $status,
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 300,
        'net_payout_cents' => 9700,
        'currency_code' => 'AUD',
        'ledger_entry_count' => 1,
        'eligible_after' => $now,
        'wallet_debit_cents' => 0,
        'charge_cents' => 0,
        'retry_count' => 0,
        'needs_manual_refund' => 0,
        'void_at' => $voidAt,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

/**
 * Phase 4+: payouts link to commerce.orders via commission_payout_items.
 * Seeds an approved order stamped with the payout_id and a payout_item row that
 * links the two — mirroring the production write path in CommissionPayoutService.
 */
function expiredPayout_seedLinkedOrder(string $orderId, string $payoutId, string $status = 'approved', int $amountCents = 10000): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => $orderId,
        'shopify_order_id' => 'shop_'.$orderId,
        'shopify_shop_domain' => 'test.myshopify.com',
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
        'status' => $status,
        'gross_cents' => $amountCents * 7,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => $amountCents * 7,
        'commission_cents' => $amountCents,
        'commission_rate' => 15,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'payout_id' => $payoutId,
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('commerce.commission_payout_items')->insert([
        'id' => 'pi_'.$orderId,
        'payout_id' => $payoutId,
        'order_id' => $orderId,
        'amount_cents' => $amountCents,
        'created_at' => $now,
    ]);
}

function expiredPayout_makeJob(): VoidExpiredPayoutsJob
{
    return new VoidExpiredPayoutsJob;
}

function expiredPayout_makeService(): CommissionVoidService
{
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->andReturnNull();

    return new CommissionVoidService($publisher);
}

it('voids expired payouts when affiliate has not connected Stripe', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString());
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved', 6000);
    expiredPayout_seedLinkedOrder('o2', 'p1', 'approved', 4000);

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    $payout = CommissionPayout::find('p1');
    expect($payout->status)->toBe('cancelled');
    expect($payout->failure_code)->toBe('grace_period_expired');

    expect(Order::find('o1')->status)->toBe('voided');
    expect(Order::find('o2')->status)->toBe('voided');

    // Linked orders' payout_id is cleared after voiding so reports don't
    // surface them as still attached to a cancelled payout.
    expect(Order::find('o1')->payout_id)->toBeNull();
    expect(Order::find('o2')->payout_id)->toBeNull();

    // Audit-log rows recorded for both voids.
    expect(OrderEvent::where('order_id', 'o1')->where('event_type', 'voided')->count())->toBe(1);
    expect(OrderEvent::where('order_id', 'o2')->where('event_type', 'voided')->count())->toBe(1);
});

it('does not void expired payouts when affiliate has active Stripe', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'active');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString());
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('pending');
    expect(Order::find('o1')->status)->toBe('approved');
});

it('does not void payouts still within their grace period', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(30)->toDateTimeString());
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('pending');
    expect(Order::find('o1')->status)->toBe('approved');
});

it('voids expired payouts in pending_funds status too', function () {
    // pending_funds is the post-charge-failure state — the partial index
    // commission_payouts_void_at_idx covers both pending and pending_funds.
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString(), status: 'pending_funds');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('cancelled');
    expect(Order::find('o1')->status)->toBe('voided');
});

it('leaves completed payouts alone even if void_at is in the past', function () {
    // Completed payouts have void_at in the past on purpose — the migration
    // backfilled void_at for every existing row. The cron must filter by
    // status so an already-paid payout cannot be retroactively cancelled.
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDays(60)->toDateTimeString(), status: 'completed');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('completed');
    expect(Order::find('o1')->status)->toBe('approved');
});

// ============================================================
// #VEP-2 — Affiliate notification on payout void
// ============================================================

it('publishes a voided notification to the affiliate when their payout expires', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString());
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')
        ->once()
        ->withArgs(fn ($professionalId, $frontendType, $category, $title, $body, $dedupeKey) => $professionalId === 'aff-1' &&
            $dedupeKey === 'payout_voided.p1' &&
            $category === 'commissions'
        );

    $service = new CommissionVoidService($publisher);
    expiredPayout_makeJob()->handle($service);
});

// ============================================================
// #VEP-6 — Optimistic-lock race coverage
// ============================================================

it('does not cancel payout or void linked orders when status changed concurrently', function () {
    // Simulate: another process (ExecuteCommissionPayoutJob) advanced the payout to
    // 'collecting' between the chunk SELECT and the optimistic-lock UPDATE inside
    // cancelExpiredPayout(). The whereIn('status', ['pending', 'pending_funds']) guard
    // must fire 0 rows updated — leaving payout and orders untouched.
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString(), status: 'pending');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    DB::connection('pgsql')
        ->table('commerce.commission_payouts')
        ->where('id', 'p1')
        ->update(['status' => 'collecting']);

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('collecting');
    expect(Order::find('o1')->status)->toBe('approved');
});

// ============================================================
// #VEP-7 — Multi-payout / chunk-boundary coverage
// ============================================================

it('processes all expired payouts across two chunks', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');

    // Seed 201 expired payouts — one more than the chunkById(200) boundary —
    // so the loop must complete at least two chunk passes.
    $past = now()->subDay()->toDateTimeString();
    $now = now()->toDateTimeString();
    $rows = [];
    for ($i = 1; $i <= 201; $i++) {
        $rows[] = [
            'id' => "chunk-p{$i}",
            'brand_professional_id' => 'brand-1',
            'affiliate_professional_id' => 'aff-1',
            'status' => 'pending',
            'gross_commission_cents' => 1000,
            'platform_fee_cents' => 30,
            'net_payout_cents' => 970,
            'currency_code' => 'AUD',
            'ledger_entry_count' => 0,
            'eligible_after' => $now,
            'wallet_debit_cents' => 0,
            'charge_cents' => 0,
            'retry_count' => 0,
            'needs_manual_refund' => 0,
            'void_at' => $past,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert($rows);

    $stats = expiredPayout_makeService()->processExpiredPayouts();

    expect($stats['cancelled_count'])->toBe(201);

    $remaining = DB::connection('pgsql')
        ->table('commerce.commission_payouts')
        ->where('status', 'pending')
        ->count();
    expect($remaining)->toBe(0);
});

it('publishes notification only once when the same expired payout is processed on two consecutive runs', function () {
    // First run cancels the payout. Second run finds no pending/pending_funds payouts
    // past void_at, so nothing is selected and publisher is never called again.
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString());
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once();

    $service = new CommissionVoidService($publisher);
    $job = expiredPayout_makeJob();
    $job->handle($service);
    $job->handle($service);
});
