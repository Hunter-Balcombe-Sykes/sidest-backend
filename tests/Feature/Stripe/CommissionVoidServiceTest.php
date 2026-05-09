<?php

use App\Models\Commerce\Order;
use App\Models\Commerce\OrderEvent;
use App\Models\Core\Professional\Professional;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow(null);
    date_default_timezone_set('UTC');
});

beforeEach(function () {
    setupProfessionalsTable();
    setupCommerceOrdersTables();

    // Stripe Connect columns missing from the shared professionals helper
    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT DEFAULT \'not_connected\'',
        'stripe_grace_period_ends_at TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
            // already exists
        }
    }

    // commission_payouts — payout-warning tests still seed real rows here.
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_commission_cents INTEGER NOT NULL DEFAULT 0,
        platform_fee_cents INTEGER NOT NULL DEFAULT 0,
        net_payout_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        failure_reason TEXT,
        failure_code TEXT,
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function seedVoidAffiliate(string $id, string $stripeStatus = 'not_connected', ?string $gracePeriodEndsAt = null): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "affiliate-{$id}",
        'handle_lc' => "affiliate-{$id}",
        'display_name' => "Test Affiliate {$id}",
        'professional_type' => 'influencer',
        'status' => 'active',
        'stripe_connect_status' => $stripeStatus,
        'stripe_grace_period_ends_at' => $gracePeriodEndsAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function seedVoidBrand(string $id): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => "Test Brand {$id}",
        'professional_type' => 'brand',
        'status' => 'active',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Phase 4+: a "voidable commission" is an approved commerce.orders row.
 * The service voids it by flipping status='voided' (and writing an order_events row).
 */
function seedVoidCommission(string $id, string $brandId, string $affiliateId, int $amountCents = 1000, ?string $createdAt = null, ?string $occurredAt = null): void
{
    $createdAt = $createdAt ?? now()->toDateTimeString();
    $occurredAt = $occurredAt ?? $createdAt;
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => $id,
        'shopify_order_id' => 'shop_'.$id,
        'shopify_shop_domain' => 'test.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => $amountCents * 7,           // 1/7 commission rate ≈ 15%
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => $amountCents * 7,
        'commission_cents' => $amountCents,
        'commission_rate' => 15.0,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'shopify_updated_at' => $occurredAt,
        'occurred_at' => $occurredAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('voids commissions past the void window for unconnected affiliates', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->andReturnNull();
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');

    // Sale was 31 days ago — past the 30-day void window.
    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(31)->toDateTimeString());

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(1)
        ->and($stats['voided_cents'])->toBe(1000);

    expect(Order::find('c1')->status)->toBe('voided');

    // An audit-log row is written for every void.
    $event = OrderEvent::where('order_id', 'c1')->where('event_type', 'voided')->first();
    expect($event)->not->toBeNull();
    expect($event->source)->toBe('system');
});

it('does not void commissions within the void window', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');

    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(20)->toDateTimeString());

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
    expect(Order::find('c1')->status)->toBe('approved');
});

it('does not void commissions for affiliates with active Stripe', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'active');

    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(31)->toDateTimeString());

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
    expect(Order::find('c1')->status)->toBe('approved');
});

it('identifies affiliates within grace period', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidAffiliate('aff-in', 'not_connected', now()->addDays(10)->toDateTimeString());
    seedVoidAffiliate('aff-out', 'not_connected', now()->subDays(5)->toDateTimeString());

    $inGrace = Professional::find('aff-in');
    $outGrace = Professional::find('aff-out');

    expect($service->isInGracePeriod($inGrace))->toBeTrue();
    expect($service->isInGracePeriod($outGrace))->toBeFalse();
});

it('does not void orders that already have a payout_id', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');

    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(31)->toDateTimeString());
    DB::connection('pgsql')->table('commerce.orders')
        ->where('id', 'c1')
        ->update(['payout_id' => 'some-payout-id']);

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
});

// ============================================================
// #V5-026 — Use occurred_at, not created_at, for void window
// ============================================================

it('voids a commission based on occurred_at when the webhook arrived late after the void window', function () {
    // Sale happened 31 days ago (occurred_at) but the Shopify webhook was delayed —
    // the row was inserted today. The void window must measure from sale date, not insertion.
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->andReturnNull();
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');

    seedVoidCommission(
        'c1', 'brand-1', 'aff-1', 1000,
        createdAt: now()->toDateTimeString(),
        occurredAt: now()->subDays(31)->toDateTimeString(),
    );

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(1);
    expect(Order::find('c1')->status)->toBe('voided');
});

// ============================================================
// #V5-026 / #V5-025 cluster — Void cutoffs must use UTC
// ============================================================

it('uses UTC for void cutoff so an app-timezone offset does not void commissions early', function () {
    // Auckland (UTC+12): now() without .utc() serializes to local time, producing a
    // cutoff 12h later than the UTC equivalent — entries still within the void window
    // would be wrongly included. now()->utc()->subDays(N) fixes this.
    date_default_timezone_set('Pacific/Auckland');
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-05-02 00:00:00', 'UTC'));

    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');

    // 29d18h ago (UTC). Inside the 30-day window.
    // UTC cutoff '2026-04-02 00:00:00' → 06:00 > 00:00 → NOT voided ✓
    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, occurredAt: '2026-04-02 06:00:00');

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
    expect(Order::find('c1')->status)->toBe('approved');
});

// ============================================================
// #VEP-2 — Per-payout expiry warnings aligned with void_at
// ============================================================

function seedVoidPayout(string $id, string $affiliateId, string $brandId, string $voidAt, string $status = 'pending', int $netPayoutCents = 9700): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
        'id' => $id,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => $status,
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 300,
        'net_payout_cents' => $netPayoutCents,
        'currency_code' => 'AUD',
        'ledger_entry_count' => 1,
        'void_at' => $voidAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('sends a 10-day warning for a payout expiring in 10 days', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')
        ->once()
        ->withArgs(fn ($professionalId, $frontendType, $category, $title, $body, $dedupeKey) => $professionalId === 'aff-1' &&
            str_contains($dedupeKey, 'payout') &&
            $category === 'commissions'
        );
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');
    seedVoidPayout('p1', 'aff-1', 'brand-1', voidAt: now()->addDays(10)->midDay()->toDateTimeString());

    $stats = $service->sendGracePeriodWarnings();

    expect($stats['warnings_sent'])->toBe(1);
});

it('sends a 2-day warning for a payout expiring in 2 days', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')
        ->once()
        ->withArgs(fn ($professionalId, $frontendType, $category, $title, $body, $dedupeKey) => $professionalId === 'aff-1' &&
            str_contains($dedupeKey, 'payout') &&
            $category === 'commissions'
        );
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');
    seedVoidPayout('p1', 'aff-1', 'brand-1', voidAt: now()->addDays(2)->midDay()->toDateTimeString());

    $stats = $service->sendGracePeriodWarnings();

    expect($stats['warnings_sent'])->toBe(1);
});

it('does not send a payout expiry warning outside the 10-day and 2-day windows', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldNotReceive('publish');
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');
    seedVoidPayout('p1', 'aff-1', 'brand-1', voidAt: now()->addDays(15)->toDateTimeString());

    $service->sendGracePeriodWarnings();
});

it('does not send a payout expiry warning when the affiliate has active Stripe', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldNotReceive('publish');
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'active');
    seedVoidPayout('p1', 'aff-1', 'brand-1', voidAt: now()->addDays(10)->midDay()->toDateTimeString());

    $service->sendGracePeriodWarnings();
});
