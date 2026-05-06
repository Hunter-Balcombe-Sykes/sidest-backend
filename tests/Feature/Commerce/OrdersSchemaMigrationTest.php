<?php

// Schema-doc test: verifies the orders-schema migration file exists and contains
// the expected DDL surface. This is a structural check — actual Postgres-specific
// behavior (RLS, triggers, jsonb_strip_pii, BRIN, ON CONFLICT WHERE) is validated
// during Phase 2 backfill against a real Supabase branch, not in CI.
//
// See docs/analytics-rebuild-plan.md (v3.1) and docs/adr/0001-analytics-rebuild.md.

beforeEach(function () {
    $this->migrationPath = base_path('supabase/migrations/20260506000000_create_orders_schema.sql');
    expect(file_exists($this->migrationPath))->toBeTrue('Phase 1 migration file is missing');
    $this->sql = file_get_contents($this->migrationPath);
});

it('extends commission_ledger_entries entry_type CHECK with clawback and adjustment', function () {
    // Rename of commission_ledger_entries → commission_movements is deferred to Phase 4
    // (see migration header comment) — touching it now would force a 30-PHP-file update.
    expect($this->sql)
        ->toContain('commission_ledger_entries_entry_type_check')
        ->toContain("CHECK (entry_type IN ('accrual','reversal','payout','clawback','adjustment'))");
});

it('creates the four new commerce tables', function () {
    expect($this->sql)
        ->toContain('CREATE TABLE commerce.orders')
        ->toContain('CREATE TABLE commerce.order_events')
        ->toContain('CREATE TABLE commerce.order_items')
        ->toContain('CREATE TABLE commerce.brand_affiliate_rollup');
});

it('uses bigint (not integer) for every _cents column', function () {
    // Audit fix: integer overflows at $21M; bigint is overflow-safe.
    expect($this->sql)
        ->toContain('gross_cents bigint')
        ->toContain('discount_cents bigint')
        ->toContain('refund_cents bigint')
        ->toContain('net_cents bigint')
        ->toContain('commission_cents bigint')
        ->toContain('amount_delta_cents bigint')
        ->toContain('unit_price_cents bigint')
        ->toContain('line_total_cents bigint');
});

it('enables RLS on every new table with party-select and staff policies', function () {
    expect($this->sql)
        ->toContain('ALTER TABLE commerce.orders ENABLE ROW LEVEL SECURITY')
        ->toContain('ALTER TABLE commerce.order_events ENABLE ROW LEVEL SECURITY')
        ->toContain('ALTER TABLE commerce.order_items ENABLE ROW LEVEL SECURITY')
        ->toContain('ALTER TABLE commerce.brand_affiliate_rollup ENABLE ROW LEVEL SECURITY')
        ->toContain('CREATE POLICY orders_party_select')
        ->toContain('CREATE POLICY orders_staff_write')
        ->toContain('CREATE POLICY order_events_party_select')
        ->toContain('CREATE POLICY order_items_party_select')
        ->toContain('CREATE POLICY rollup_party_select')
        ->toContain('CREATE POLICY rollup_staff_all');
});

it('defines stub status in CHECK constraint and excludes it from rollup trigger', function () {
    expect($this->sql)
        ->toContain("'stub'")  // included in status CHECK
        ->toContain("IF NEW.status = 'stub' THEN")  // skipped by rollup trigger
        ->toContain('OR (TG_OP = \'UPDATE\' AND OLD.status = \'stub\')');
});

it('adds order_id columns to commission_ledger_entries and commission_payout_items with FKs', function () {
    expect($this->sql)
        ->toContain('ALTER TABLE commerce.commission_payout_items')
        ->toContain('ADD COLUMN IF NOT EXISTS order_id uuid')
        ->toContain('ALTER TABLE commerce.commission_ledger_entries')
        ->toContain('commission_payout_items_order_id_fkey')
        ->toContain('commission_ledger_entries_order_id_fkey');
});

it('creates the BRIN index on occurred_at', function () {
    expect($this->sql)
        ->toContain('idx_orders_occurred_brin')
        ->toContain('USING BRIN(occurred_at)');
});

it('creates the unique index for X-Shopify-Event-Id idempotency', function () {
    expect($this->sql)
        ->toContain('uq_order_events_shopify_event')
        ->toContain('WHERE shopify_event_id IS NOT NULL');
});

it('creates the rollup trigger with INSERT-and-UPDATE coverage', function () {
    expect($this->sql)
        ->toContain('CREATE OR REPLACE FUNCTION commerce.rollup_apply_delta()')
        ->toContain('CREATE TRIGGER trg_rollup')
        ->toContain('AFTER INSERT OR UPDATE ON commerce.orders');
});

it('creates the clawback trigger with order_id requirement', function () {
    expect($this->sql)
        ->toContain('CREATE OR REPLACE FUNCTION commerce.rollup_apply_clawback()')
        ->toContain('CREATE TRIGGER trg_rollup_clawback')
        ->toContain('AFTER INSERT ON commerce.commission_ledger_entries')
        ->toContain('Clawback movement requires order_id');
});

it('creates the order_items trigger driven by line_items JSONB', function () {
    expect($this->sql)
        ->toContain('CREATE OR REPLACE FUNCTION commerce.order_items_diff()')
        ->toContain('CREATE TRIGGER trg_order_items_diff')
        ->toContain('AFTER INSERT OR UPDATE OF line_items ON commerce.orders');
});

it('defines jsonb_strip_pii with wildcard support', function () {
    expect($this->sql)
        ->toContain('CREATE OR REPLACE FUNCTION public.jsonb_strip_pii(input jsonb, paths text[])')
        ->toContain("position('[*]' IN path_str)")
        ->toContain('IMMUTABLE');
});

it('uses ON CONFLICT DO UPDATE pattern for rollup deltas', function () {
    // Signed-delta UPSERT — never DELETE-then-INSERT
    expect($this->sql)
        ->toContain('ON CONFLICT (day, brand_professional_id, affiliate_professional_id, currency_code)')
        ->toContain('DO UPDATE SET');
});

it('wraps the migration in a single transaction', function () {
    expect(trim($this->sql))->toStartWith('-- Analytics & Data Layer Rebuild');
    expect($this->sql)
        ->toContain('BEGIN;')
        ->toContain('COMMIT;');
});
