<?php

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Jobs\Stripe\VoidExpiredPayoutsJob;
use App\Models\Commerce\CommissionPayout;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderEvent;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// Tests for the nightly cron that voids commission payouts whose 60-day grace
// window has expired without the affiliate connecting Stripe Connect.
// Closes #CR-003 — UI promised 60-day grace but no enforcement existed.
//
// v2 state machine: pending → processing → completed | failed | cancelled.
// The legacy pending_funds and collecting statuses are removed.

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow(null);
});

beforeEach(function () {
    Notification::fake();

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
        }
    }

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

    foreach ([
        "grace_notifications_sent TEXT NOT NULL DEFAULT '[]'",
        'transfer_completed_at TEXT',
        'funding_failure_count INTEGER NOT NULL DEFAULT 0',
        'failure_category TEXT',
        'grace_started_at TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE commerce.commission_payouts ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    foreach (['primary_email TEXT'] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    // Unified-pipeline target: NotificationPublisher writes here; SendTransactionalNotificationEmailJob
    // dispatches off newly-inserted rows. Schema mirrors the canonical migration columns.
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS notifications");
    } catch (\Throwable) {
    }
    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        type TEXT NOT NULL,
        category TEXT NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        dedupe_key TEXT NULL,
        email_sent_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $conn->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (professional_id, dedupe_key)
         WHERE dedupe_key IS NOT NULL'
    );

    // Tests using the real publisher want a clean Bus to assert email dispatches.
    Bus::fake();

    // Email path off by default; tests that exercise it flip it on themselves.
    Config::set('partna.notifications.email_enabled', false);
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
        'primary_email' => "affiliate-{$id}@test.test",
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
        'charge_cents' => 0,
        'retry_count' => 0,
        'needs_manual_refund' => 0,
        'void_at' => $voidAt,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

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

// Real NotificationPublisher used by the job's grace-warning path. Tests that
// don't care about the warning side just pass the result through; tests that
// DO care construct a fresh real publisher and inspect rows in
// notifications.notifications afterwards.
function expiredPayout_makePublisher(): NotificationPublisher
{
    return new NotificationPublisher;
}

it('voids expired payouts when affiliate has not connected Stripe', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString());
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved', 6000);
    expiredPayout_seedLinkedOrder('o2', 'p1', 'approved', 4000);

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    $payout = CommissionPayout::find('p1');
    expect($payout->status)->toBe('cancelled');
    expect($payout->failure_code)->toBe('grace_period_expired');

    expect(Order::find('o1')->status)->toBe('voided');
    expect(Order::find('o2')->status)->toBe('voided');

    expect(Order::find('o1')->payout_id)->toBeNull();
    expect(Order::find('o2')->payout_id)->toBeNull();

    expect(OrderEvent::where('order_id', 'o1')->where('event_type', 'voided')->count())->toBe(1);
    expect(OrderEvent::where('order_id', 'o2')->where('event_type', 'voided')->count())->toBe(1);
});

it('does not void expired payouts when affiliate has active Stripe', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'active');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString());
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    expect(CommissionPayout::find('p1')->status)->toBe('pending');
    expect(Order::find('o1')->status)->toBe('approved');
});

it('does not void payouts still within their grace period', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(30)->toDateTimeString());
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    expect(CommissionPayout::find('p1')->status)->toBe('pending');
    expect(Order::find('o1')->status)->toBe('approved');
});

it('leaves completed payouts alone even if void_at is in the past', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDays(60)->toDateTimeString(), status: 'completed');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

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
    expiredPayout_makeJob()->handle($service, expiredPayout_makePublisher());
});

// ============================================================
// #VEP-6 — Optimistic-lock race coverage (v2: processing, not collecting)
// ============================================================

it('does not cancel payout or void linked orders when status changed to processing concurrently', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString(), status: 'pending');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    DB::connection('pgsql')
        ->table('commerce.commission_payouts')
        ->where('id', 'p1')
        ->update(['status' => 'processing']);

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    expect(CommissionPayout::find('p1')->status)->toBe('processing');
    expect(Order::find('o1')->status)->toBe('approved');
});

// ============================================================
// #VEP-7 — Multi-payout / chunk-boundary coverage
// ============================================================

it('processes all expired payouts across two chunks', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');

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
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString());
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once();

    $service = new CommissionVoidService($publisher);
    $job = expiredPayout_makeJob();
    $job->handle($service, expiredPayout_makePublisher());
    $job->handle($service, expiredPayout_makePublisher());
});

// ============================================================
// Grace warning notifications (T-30 / T-7 / T-1)
//
// Under Option A the warning anchors on `void_at` directly — fired $daysOut days before
// void_at, regardless of any legacy grace_started_at marker. The previous v1 model used
// grace_started_at as the warning anchor because void_at moved on each funding retry;
// under v2 the retry loop is gone, so void_at is stable and is the right anchor.
// ============================================================

it('publishes a T-30 grace warning row to the affiliate when void_at is 30 days away', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');

    expiredPayout_seedPayout('p1', voidAt: now()->addDays(30)->toDateTimeString(), status: 'pending');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    $row = DB::table('notifications.notifications')
        ->where('professional_id', 'aff-1')
        ->where('dedupe_key', 'payout_warning.p1.t-30')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->category)->toBe('payout_warnings');
    expect($row->type)->toBe('Warning');
    expect($row->cta_url)->toBe('/affiliate/stripe/connect');
    expect($row->title)->toContain('30 days');

    $payout = CommissionPayout::find('p1');
    expect($payout->grace_notifications_sent)->toContain('T-30');
});

it('dispatches the unified email job when email is enabled', function () {
    Config::set('partna.notifications.email_enabled', true);

    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(30)->toDateTimeString(), status: 'pending');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    Bus::assertDispatched(
        SendTransactionalNotificationEmailJob::class,
        fn ($job) => true,
    );
});

it('does not re-fire a grace warning if T-30 already recorded in grace_notifications_sent', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(30)->toDateTimeString(), status: 'pending', overrides: [
        'grace_notifications_sent' => '["T-30"]',
    ]);
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    expect(DB::table('notifications.notifications')->where('category', 'payout_warnings')->count())->toBe(0);
});

it('does not send a grace warning when affiliate already has active Stripe Connect', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'active');
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(30)->toDateTimeString(), status: 'pending');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    expect(DB::table('notifications.notifications')->where('category', 'payout_warnings')->count())->toBe(0);
});

it('does not send a grace warning when void_at is far in the future (not yet in any warning window)', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    // void_at +45d falls outside the T-30 / T-7 / T-1 windows.
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(45)->toDateTimeString(), status: 'pending');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    expect(DB::table('notifications.notifications')->where('category', 'payout_warnings')->count())->toBe(0);
});

it('fires T-7 when void_at is 7 days away', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(7)->toDateTimeString(), status: 'pending');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    $row = DB::table('notifications.notifications')
        ->where('professional_id', 'aff-1')
        ->where('dedupe_key', 'payout_warning.p1.t-7')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->type)->toBe('Warning');
    expect($row->title)->toContain('7 days');
});

it('fires T-1 with Critical severity when void_at is 1 day away', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(1)->toDateTimeString(), status: 'pending');
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    $row = DB::table('notifications.notifications')
        ->where('professional_id', 'aff-1')
        ->where('dedupe_key', 'payout_warning.p1.t-1')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->type)->toBe('Critical');
    expect($row->title)->toMatch('/(tomorrow|24 hours|final)/i');
});

it('routes a brand-side failure-code warning to the brand, not the affiliate', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'active'); // active — affiliate cannot fix, brand must
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(7)->toDateTimeString(), status: 'pending', overrides: [
        'failure_code' => 'brand_payment_method_missing',
    ]);
    expiredPayout_seedLinkedOrder('o1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService(), expiredPayout_makePublisher());

    $brandRow = DB::table('notifications.notifications')
        ->where('professional_id', 'brand-1')
        ->where('category', 'payout_warnings')
        ->first();
    expect($brandRow)->not->toBeNull();
    expect($brandRow->cta_url)->toBe('/account/settings?section=stripe');

    $affRow = DB::table('notifications.notifications')
        ->where('professional_id', 'aff-1')
        ->where('category', 'payout_warnings')
        ->first();
    expect($affRow)->toBeNull();
});
