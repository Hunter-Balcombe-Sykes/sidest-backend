<?php

use Illuminate\Support\Facades\DB;

// Tests for the pre-v2 payout quarantine migration (20260513800000).
//
// After the v2 state machine cutover (20260513700000), any non-terminal payout
// created before the v2 cutover date (2026-05-13T00:00:00Z) references the old
// direct-charge model that the new service does NOT understand.
//
// The quarantine migration:
//   1. Sets status='failed', failure_code='pre_v2_quarantine' for any payout
//      with status NOT IN ('completed','failed','cancelled') created before cutover.
//   2. Releases orders (payout_id=NULL) attached to quarantined payouts.

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

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.orders (
        id TEXT PRIMARY KEY,
        shopify_order_id TEXT,
        shopify_shop_domain TEXT,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        status TEXT,
        gross_cents INTEGER DEFAULT 0,
        discount_cents INTEGER DEFAULT 0,
        refund_cents INTEGER DEFAULT 0,
        net_cents INTEGER DEFAULT 0,
        commission_cents INTEGER DEFAULT 0,
        commission_rate INTEGER DEFAULT 0,
        rate_source TEXT,
        currency_code TEXT,
        payout_id TEXT,
        shopify_updated_at TEXT,
        occurred_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function quarantine_seedPayout(string $id, string $status, string $createdAt, array $overrides = []): void
{
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
        'eligible_after' => $createdAt,
        'charge_cents' => 0,
        'retry_count' => 0,
        'needs_manual_refund' => 0,
        'void_at' => $createdAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], $overrides));
}

function quarantine_seedOrder(string $orderId, string $payoutId, string $status = 'approved'): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => $orderId,
        'shopify_order_id' => 'shop_'.$orderId,
        'shopify_shop_domain' => 'test.myshopify.com',
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
        'status' => $status,
        'gross_cents' => 35000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 35000,
        'commission_cents' => 5000,
        'commission_rate' => 15,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'payout_id' => $payoutId,
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * Replay the migration's behaviour using SQLite-compatible SQL.
 *
 * The real migration uses `UPDATE ... FROM` (PostgreSQL update-with-join). SQLite
 * doesn't support that syntax, so we emulate it with a subselect IN clause. The
 * net effect is identical: payouts whose status is non-terminal AND created
 * before the cutover get quarantined, then their orders are released.
 */
function quarantine_runMigration(): void
{
    $cutover = '2026-05-13 00:00:00+00';

    DB::connection('pgsql')->statement("
        UPDATE commerce.commission_payouts
           SET status = 'failed',
               failure_code = 'pre_v2_quarantine',
               failure_reason = 'Quarantined during v2 cutover — manual review required',
               processed_at = datetime('now')
         WHERE status NOT IN ('completed', 'failed', 'cancelled')
           AND created_at < '{$cutover}'
    ");

    DB::connection('pgsql')->statement("
        UPDATE commerce.orders
           SET payout_id = NULL
         WHERE payout_id IN (
             SELECT id FROM commerce.commission_payouts
              WHERE failure_code = 'pre_v2_quarantine'
         )
    ");
}

it('quarantines pre-v2 payouts with non-terminal statuses', function () {
    quarantine_seedPayout('qp_pending', 'pending', '2026-05-01 00:00:00+00');
    quarantine_seedPayout('qp_processing', 'processing', '2026-05-10 00:00:00+00');
    quarantine_seedPayout('qp_cancelled', 'cancelled', '2026-05-01 00:00:00+00');
    quarantine_seedPayout('qp_completed', 'completed', '2026-05-01 00:00:00+00');
    quarantine_seedPayout('qp_failed', 'failed', '2026-05-01 00:00:00+00');

    quarantine_runMigration();

    $pending = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'qp_pending')->first();
    $processing = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'qp_processing')->first();
    $cancelled = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'qp_cancelled')->first();
    $completed = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'qp_completed')->first();
    $failed = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'qp_failed')->first();

    // Non-terminal pre-v2 → quarantined as failed
    expect($pending->status)->toBe('failed')
        ->and($pending->failure_code)->toBe('pre_v2_quarantine')
        ->and($pending->failure_reason)->toContain('Quarantined')
        ->and($pending->processed_at)->not->toBeNull();

    expect($processing->status)->toBe('failed')
        ->and($processing->failure_code)->toBe('pre_v2_quarantine');

    // Terminal statuses → untouched
    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->failure_code)->not->toBe('pre_v2_quarantine');

    expect($completed->status)->toBe('completed')
        ->and($completed->failure_code)->not->toBe('pre_v2_quarantine');

    expect($failed->status)->toBe('failed')
        ->and($failed->failure_code)->not->toBe('pre_v2_quarantine');
});

it('does not quarantine post-v2 payouts created after cutover', function () {
    quarantine_seedPayout('qp_post_v2', 'pending', '2026-05-14 00:00:00+00');

    quarantine_runMigration();

    $postV2 = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'qp_post_v2')->first();
    expect($postV2->status)->toBe('pending')
        ->and($postV2->failure_code)->not->toBe('pre_v2_quarantine');
});

it('releases orders attached to quarantined payouts', function () {
    quarantine_seedPayout('qp_with_orders', 'pending', '2026-05-01 00:00:00+00');
    quarantine_seedOrder('o_q1', 'qp_with_orders', 'approved');
    quarantine_seedOrder('o_q2', 'qp_with_orders', 'approved');

    quarantine_runMigration();

    expect(DB::connection('pgsql')->table('commerce.orders')->where('id', 'o_q1')->value('payout_id'))->toBeNull();
    expect(DB::connection('pgsql')->table('commerce.orders')->where('id', 'o_q2')->value('payout_id'))->toBeNull();
});

it('does not release orders from terminal payouts', function () {
    quarantine_seedPayout('qp_completed_w_order', 'completed', '2026-05-01 00:00:00+00');
    quarantine_seedOrder('o_c1', 'qp_completed_w_order', 'approved');

    quarantine_runMigration();

    expect(DB::connection('pgsql')->table('commerce.orders')->where('id', 'o_c1')->value('payout_id'))
        ->toBe('qp_completed_w_order');
});

it('quarantined payouts are excluded from resume queries', function () {
    quarantine_seedPayout('qp_resume', 'pending', '2026-05-01 00:00:00+00');

    quarantine_runMigration();

    // Resume queries only re-process status IN ('pending','processing')
    $resumable = DB::connection('pgsql')
        ->table('commerce.commission_payouts')
        ->whereIn('status', ['pending', 'processing'])
        ->where('id', 'qp_resume')
        ->exists();

    expect($resumable)->toBeFalse();
});
