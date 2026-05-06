<?php

// Schema-doc test for the deferred rename migration that renames
// commerce.commission_ledger_entries → commission_movements (Decision #1 in
// the analytics-rebuild plan). The migration must use ALTER TABLE RENAME
// (preserves data + indexes + FKs + the trg_rollup_clawback trigger) rather
// than a drop-and-recreate, and must rename the indexes/constraints whose
// names embedded the old short-form 'cle'.

beforeEach(function () {
    $this->migrationPath = base_path('supabase/migrations/20260506600000_rename_ledger_to_movements.sql');
    expect(file_exists($this->migrationPath))->toBeTrue('Rename migration file is missing');
    $this->sql = file_get_contents($this->migrationPath);
});

it('wraps the rename in a single transaction', function () {
    expect(substr_count($this->sql, 'BEGIN;'))->toBe(1);
    expect(substr_count($this->sql, 'COMMIT;'))->toBe(1);
});

it('renames the table via ALTER TABLE RENAME, not DROP + CREATE', function () {
    expect($this->sql)
        ->toContain('ALTER TABLE commerce.commission_ledger_entries')
        ->toContain('RENAME TO commission_movements');
    expect($this->sql)->not->toContain('DROP TABLE commerce.commission_ledger_entries');
});

it('renames the entry_type CHECK constraint to match the new table name', function () {
    expect($this->sql)
        ->toContain('RENAME CONSTRAINT commission_ledger_entries_entry_type_check')
        ->toContain('TO commission_movements_entry_type_check');
});

it('renames the order_id FK constraint guarded by an IF EXISTS check', function () {
    // The FK was added in Phase 1; the IF EXISTS guard makes the rename safe
    // even if the constraint name is unexpectedly missing in some environment.
    expect($this->sql)
        ->toContain('commission_ledger_entries_order_id_fkey')
        ->toContain('commission_movements_order_id_fkey')
        ->toContain('pg_constraint');
});

it('renames the cle order_id index to match the cm prefix', function () {
    expect($this->sql)
        ->toContain('ALTER INDEX IF EXISTS commerce.idx_cle_order_id')
        ->toContain('RENAME TO idx_cm_order_id');
});
