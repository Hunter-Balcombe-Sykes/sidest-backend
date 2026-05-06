<?php

// Phase 4 schema-doc test: verifies the migration file that drops the eight
// legacy analytics aggregate tables and the obsolete commission_ledger_entry_id
// FK column exists and contains the expected DDL surface in the load-bearing
// statement order (audit fix PLANV3-7). The actual DDL effect is verified via
// post-deploy SQL (§11) — this is a structural check that locks in the
// migration's shape so a careless future edit can't reorder the statements
// or drop the wrap-in-transaction.

beforeEach(function () {
    $this->migrationPath = base_path('supabase/migrations/20260506500000_drop_legacy_aggregates.sql');
    expect(file_exists($this->migrationPath))->toBeTrue('Phase 4 migration file is missing');
    $this->sql = file_get_contents($this->migrationPath);
});

it('wraps every statement in a single transaction', function () {
    // Single BEGIN/COMMIT pair so partial failure rolls back cleanly.
    expect(substr_count($this->sql, 'BEGIN;'))->toBe(1);
    expect(substr_count($this->sql, 'COMMIT;'))->toBe(1);
    expect(strpos($this->sql, 'BEGIN;'))->toBeLessThan(strpos($this->sql, 'COMMIT;'));
});

it('runs the destructive steps in load-bearing order', function () {
    // Audit fix PLANV3-7: SET NOT NULL → DROP COLUMN → DELETE → DROP TABLE.
    // Reordering breaks the migration: DELETE before DROP COLUMN is blocked
    // by the FK; SET NOT NULL only succeeds after Phase 2 backfilled order_id.
    // Strip the leading comment block so strpos doesn't match doc text.
    $body = preg_replace('/^(?:--[^\n]*\n)+/', '', $this->sql);

    $setNotNullPos = strpos($body, 'SET NOT NULL');
    $dropColumnPos = strpos($body, 'DROP COLUMN commission_ledger_entry_id');
    $deletePos = strpos($body, 'DELETE FROM commerce.commission_ledger_entries');
    $dropTablePos = strpos($body, 'DROP TABLE analytics.brand_metrics_daily');

    expect($setNotNullPos)->not->toBeFalse();
    expect($dropColumnPos)->not->toBeFalse();
    expect($deletePos)->not->toBeFalse();
    expect($dropTablePos)->not->toBeFalse();

    expect($setNotNullPos)->toBeLessThan($dropColumnPos);
    expect($dropColumnPos)->toBeLessThan($deletePos);
    expect($deletePos)->toBeLessThan($dropTablePos);
});

it('promotes commission_payout_items.order_id to NOT NULL', function () {
    expect($this->sql)->toContain('ALTER TABLE commerce.commission_payout_items')
        ->toContain('ALTER COLUMN order_id SET NOT NULL');
});

it('drops commission_ledger_entry_id FK column', function () {
    expect($this->sql)->toContain('ALTER TABLE commerce.commission_payout_items')
        ->toContain('DROP COLUMN commission_ledger_entry_id');
});

it('deletes accrual and reversal ledger rows but no other entry types', function () {
    // payout/clawback/adjustment must survive — they are the post-Phase-3
    // money-movement audit log. A regex on the WHERE clause locks the
    // entry-type list and keeps the migration honest if a future edit
    // tries to widen the DELETE.
    expect($this->sql)->toContain('DELETE FROM commerce.commission_ledger_entries');
    expect($this->sql)->toMatch("/WHERE entry_type IN \('accrual', 'reversal'\)/");
});

it('drops all eight legacy analytics aggregate tables in one statement', function () {
    expect($this->sql)
        ->toContain('analytics.brand_metrics_daily')
        ->toContain('analytics.brand_metrics_hourly')
        ->toContain('analytics.brand_affiliate_daily')
        ->toContain('analytics.brand_commission_daily')
        ->toContain('analytics.professional_metrics_daily')
        ->toContain('analytics.professional_metrics_hourly')
        ->toContain('analytics.site_metrics_daily')
        ->toContain('analytics.site_metrics_hourly');
});

it('does NOT use IF EXISTS on DROP TABLE', function () {
    // We want loud failure if any of these tables is missing — that means
    // schema drift between environments and we want to see it, not paper over.
    expect($this->sql)->not->toContain('DROP TABLE IF EXISTS');
});
