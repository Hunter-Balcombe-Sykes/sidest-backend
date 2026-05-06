<?php

use App\Jobs\Stripe\VoidExpiredPayoutsJob;
use App\Models\Retail\CommissionLedgerEntry;
use App\Models\Retail\CommissionPayout;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Support\Facades\DB;

// Tests for the nightly cron that voids commission payouts whose 60-day grace
// window has expired without the affiliate connecting Stripe Connect.
// Closes #CR-003 — UI promised 60-day grace but no enforcement existed.

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow(null);
});

beforeEach(function () {
    setupProfessionalsTable();

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

    // clearOrderStampsForVoidedPayout queries this table; must exist even when no order-based
    // items are seeded (the method no-ops when the pluck returns empty).
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY,
        payout_id TEXT,
        order_id TEXT,
        amount_cents INTEGER,
        created_at TEXT
    )');

    // commerce.orders — needed so clearOrderStampsForVoidedPayout can clear payout_id.
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.orders (
        id TEXT PRIMARY KEY,
        shopify_order_id TEXT,
        shopify_shop_domain TEXT,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        status TEXT,
        payout_id TEXT,
        gross_cents INTEGER DEFAULT 0,
        discount_cents INTEGER DEFAULT 0,
        refund_cents INTEGER DEFAULT 0,
        net_cents INTEGER DEFAULT 0,
        commission_cents INTEGER DEFAULT 0,
        commission_rate REAL DEFAULT 0,
        rate_source TEXT,
        currency_code TEXT,
        line_items TEXT DEFAULT \'[]\',
        shopify_data TEXT DEFAULT \'{}\',
        shopify_updated_at TEXT,
        occurred_at TEXT,
        created_at TEXT,
        updated_at TEXT
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

function expiredPayout_seedLedgerEntry(string $id, string $payoutId, string $status = 'approved', int $amountCents = 10000): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_ledger_entries')->insert([
        'id' => $id,
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
        'entry_type' => 'accrual',
        'status' => $status,
        'amount_cents' => $amountCents,
        'currency_code' => 'AUD',
        'commission_rate' => 15.0,
        'rate_source' => 'brand_default',
        'idempotency_key' => "test-{$id}",
        'calculation_metadata' => '{}',
        'payout_id' => $payoutId,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
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
    expiredPayout_seedLedgerEntry('l1', 'p1', 'approved', 6000);
    expiredPayout_seedLedgerEntry('l2', 'p1', 'approved', 4000);

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    $payout = CommissionPayout::find('p1');
    expect($payout->status)->toBe('cancelled');
    expect($payout->failure_code)->toBe('grace_period_expired');

    expect(CommissionLedgerEntry::find('l1')->status)->toBe('voided');
    expect(CommissionLedgerEntry::find('l1')->void_reason)->toBe('payout_grace_expired');
    expect(CommissionLedgerEntry::find('l1')->voided_at)->not->toBeNull();
    expect(CommissionLedgerEntry::find('l2')->status)->toBe('voided');
});

it('does not void expired payouts when affiliate has active Stripe', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'active');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString());
    expiredPayout_seedLedgerEntry('l1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('pending');
    expect(CommissionLedgerEntry::find('l1')->status)->toBe('approved');
});

it('does not void payouts still within their grace period', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->addDays(30)->toDateTimeString());
    expiredPayout_seedLedgerEntry('l1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('pending');
    expect(CommissionLedgerEntry::find('l1')->status)->toBe('approved');
});

it('voids expired payouts in pending_funds status too', function () {
    // pending_funds is the post-charge-failure state — the partial index
    // commission_payouts_void_at_idx covers both pending and pending_funds.
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString(), status: 'pending_funds');
    expiredPayout_seedLedgerEntry('l1', 'p1', 'approved');

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('cancelled');
    expect(CommissionLedgerEntry::find('l1')->status)->toBe('voided');
});

it('leaves completed payouts alone even if void_at is in the past', function () {
    // Completed payouts have void_at in the past on purpose — the migration
    // backfilled void_at for every existing row. The cron must filter by
    // status so an already-paid payout cannot be retroactively cancelled.
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDays(60)->toDateTimeString(), status: 'completed');
    expiredPayout_seedLedgerEntry('l1', 'p1', 'paid');

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('completed');
    expect(CommissionLedgerEntry::find('l1')->status)->toBe('paid');
});

// ============================================================
// #VEP-2 — Affiliate notification on payout void
// ============================================================

it('publishes a voided notification to the affiliate when their payout expires', function () {
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString());
    expiredPayout_seedLedgerEntry('l1', 'p1', 'approved');

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

it('does not cancel payout or void ledger entries when status changed concurrently', function () {
    // Simulate: another process (ExecuteCommissionPayoutJob) advanced the payout to
    // 'collecting' between the chunk SELECT and the optimistic-lock UPDATE inside
    // cancelExpiredPayout(). The whereIn('status', ['pending', 'pending_funds']) guard
    // must fire 0 rows updated — leaving payout and ledger untouched.
    expiredPayout_seedBrand('brand-1');
    expiredPayout_seedAffiliate('aff-1', 'not_connected');
    expiredPayout_seedPayout('p1', voidAt: now()->subDay()->toDateTimeString(), status: 'pending');
    expiredPayout_seedLedgerEntry('l1', 'p1', 'approved');

    // Race: advance status before the cron processes it
    DB::connection('pgsql')
        ->table('commerce.commission_payouts')
        ->where('id', 'p1')
        ->update(['status' => 'collecting']);

    expiredPayout_makeJob()->handle(expiredPayout_makeService());

    expect(CommissionPayout::find('p1')->status)->toBe('collecting');
    expect(CommissionLedgerEntry::find('l1')->status)->toBe('approved');
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
    expiredPayout_seedLedgerEntry('l1', 'p1', 'approved');

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once();

    $service = new CommissionVoidService($publisher);
    $job = expiredPayout_makeJob();
    $job->handle($service);
    $job->handle($service);
});
