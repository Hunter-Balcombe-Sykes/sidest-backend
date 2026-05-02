<?php

use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow(null);
    date_default_timezone_set('UTC');
});

beforeEach(function () {
    setupProfessionalsTable();

    // Add Stripe columns missing from the shared helper
    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT DEFAULT \'not_connected\'',
        'stripe_grace_period_ends_at TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
            // column already exists
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_ledger_entries (
        id TEXT PRIMARY KEY,
        shopify_order_id TEXT,
        brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL,
        entry_type TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\',
        amount_cents INTEGER NOT NULL,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        commission_rate REAL NOT NULL,
        rate_source TEXT NOT NULL,
        idempotency_key TEXT NOT NULL,
        calculation_metadata TEXT NOT NULL DEFAULT \'{}\',
        payout_id TEXT,
        voided_at TEXT,
        void_reason TEXT,
        occurred_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
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

function seedVoidCommission(string $id, string $brandId, string $affiliateId, int $amountCents = 1000, ?string $createdAt = null, ?string $occurredAt = null): void
{
    $createdAt = $createdAt ?? now()->toDateTimeString();
    $occurredAt = $occurredAt ?? $createdAt;
    DB::connection('pgsql')->table('commerce.commission_ledger_entries')->insert([
        'id' => $id,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'entry_type' => 'accrual',
        'status' => 'pending',
        'amount_cents' => $amountCents,
        'currency_code' => 'AUD',
        'commission_rate' => 15.0,
        'rate_source' => 'brand_default',
        'idempotency_key' => "test-{$id}",
        'calculation_metadata' => '{}',
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

    // Commission created 31 days ago — past the 30-day void window
    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(31)->toDateTimeString());

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(1);
    expect($stats['voided_cents'])->toBe(1000);

    $entry = CommissionLedgerEntry::find('c1');
    expect($entry->status)->toBe('voided');
    expect($entry->void_reason)->toBe('no_stripe_connected');
    expect($entry->voided_at)->not->toBeNull();
});

it('does not void commissions within the void window', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');

    // Commission created 20 days ago — still within the 30-day window
    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(20)->toDateTimeString());

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('pending');
});

it('does not void commissions for affiliates with active Stripe', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'active');

    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(31)->toDateTimeString());

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('pending');
});

it('flushes held commissions to approved on Stripe connect', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'active');

    // Commission created 10 days ago — within void window, should flush
    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(10)->toDateTimeString());
    // Commission created 35 days ago — past void window, should NOT flush
    seedVoidCommission('c2', 'brand-1', 'aff-1', 2000, now()->subDays(35)->toDateTimeString());

    $affiliate = Professional::find('aff-1');
    $count = $service->flushHeldCommissions($affiliate);

    expect($count)->toBe(1);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('approved');
    expect(CommissionLedgerEntry::find('c2')->status)->toBe('pending');
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

it('does not void commissions that already have a payout_id', function () {
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');

    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000, now()->subDays(31)->toDateTimeString());
    // Simulate already linked to a payout
    DB::connection('pgsql')->table('commerce.commission_ledger_entries')
        ->where('id', 'c1')
        ->update(['payout_id' => 'some-payout-id']);

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
});

// ============================================================
// #V5-026 — Use occurred_at, not created_at, for void window
// ============================================================

it('voids a commission based on occurred_at when the webhook arrived late after the void window', function () {
    // Scenario: sale happened 31 days ago (occurred_at) but the Shopify webhook
    // was delayed — the DB row was only inserted today (created_at = now).
    // The void window (30 days) should be measured from the sale date, not the insertion date.
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'not_connected');

    seedVoidCommission(
        'c1', 'brand-1', 'aff-1', 1000,
        createdAt: now()->toDateTimeString(),            // webhook processed today
        occurredAt: now()->subDays(31)->toDateTimeString(), // sale was 31 days ago
    );

    $stats = $service->processVoidableCommissions();

    // Should be voided: occurred_at is past the 30-day window
    expect($stats['voided_count'])->toBe(1);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('voided');
});

it('does not flush a held commission when occurred_at is past the void window even if created_at is recent', function () {
    // Scenario: sale happened 31 days ago (past the 30-day void window) but the webhook
    // arrived 2 days late — created_at is 29 days ago (within window).
    // flushHeldCommissions should NOT flush this: the void window based on the sale date has expired.
    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'active');

    seedVoidCommission(
        'c1', 'brand-1', 'aff-1', 1000,
        createdAt: now()->subDays(29)->toDateTimeString(),  // late webhook insertion
        occurredAt: now()->subDays(31)->toDateTimeString(), // sale expired the void window
    );

    $affiliate = Professional::find('aff-1');
    $count = $service->flushHeldCommissions($affiliate);

    expect($count)->toBe(0);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('pending');
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

    // Sale was 29 days and 18 hours ago (UTC). Still inside the 30-day void window.
    // UTC cutoff    '2026-04-02 00:00:00' → entry at 06:00 > 00:00 → NOT voided ✓
    // App-TZ cutoff '2026-04-02 12:00:00' → entry at 06:00 ≤ 12:00 → voided EARLY ✗
    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000,
        occurredAt: '2026-04-02 06:00:00',
    );

    $stats = $service->processVoidableCommissions();

    expect($stats['voided_count'])->toBe(0);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('pending');
});

it('uses UTC for flush cutoff so an app-timezone offset does not exclude commissions still inside the void window', function () {
    // Same Auckland scenario: now()->subDays(30) without UTC serializes 12h later,
    // so flushHeldCommissions() would miss entries still within the window.
    date_default_timezone_set('Pacific/Auckland');
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-05-02 00:00:00', 'UTC'));

    $publisher = Mockery::mock(NotificationPublisher::class);
    $service = new CommissionVoidService($publisher);

    seedVoidBrand('brand-1');
    seedVoidAffiliate('aff-1', 'active');

    // Same entry: 29d18h old (UTC). Inside the 30-day void window.
    // UTC cutoff: occurred_at > '2026-04-02 00:00:00' → 06:00 > 00:00 → flushed ✓
    // App-TZ:     occurred_at > '2026-04-02 12:00:00' → 06:00 NOT > 12:00 → not flushed ✗
    seedVoidCommission('c1', 'brand-1', 'aff-1', 1000,
        occurredAt: '2026-04-02 06:00:00',
    );

    $affiliate = Professional::find('aff-1');
    $count = $service->flushHeldCommissions($affiliate);

    expect($count)->toBe(1);
    expect(CommissionLedgerEntry::find('c1')->status)->toBe('approved');
});
