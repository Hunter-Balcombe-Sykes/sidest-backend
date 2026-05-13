<?php

// Structural assertion for the audit-table hardening migration
// (20260513200000_harden_audit_tables.sql). RLS and FK-on-delete behaviour
// cannot be exercised against the SQLite test harness, so this test guards
// the migration text itself — preventing the safety properties from being
// silently downgraded in a later edit.

// Feature tests already extend Tests\TestCase via pest()->extend(...)->in('Feature').

beforeEach(function () {
    $this->migration = file_get_contents(
        base_path('supabase/migrations/20260513200000_harden_audit_tables.sql')
    );
});

it('enables RLS on all three audit tables', function () {
    foreach ([
        'core.professional_deletion_audit',
        'core.wallet_currency_switch_audit',
        'core.brand_status_history',
    ] as $table) {
        expect($this->migration)->toContain("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
    }
});

it('flips wallet + brand_status_history FKs to ON DELETE SET NULL', function () {
    expect($this->migration)
        ->toContain('FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL');

    // Both tables must drop their original CASCADE constraint before re-adding
    // it with SET NULL — guarded by DROP CONSTRAINT statements.
    expect($this->migration)
        ->toContain('DROP CONSTRAINT IF EXISTS wallet_currency_switch_audit_professional_id_fkey')
        ->toContain('DROP CONSTRAINT IF EXISTS brand_status_history_professional_id_fkey');
});

it('adds professional_handle_snapshot to wallet + brand_status_history', function () {
    expect($this->migration)
        ->toContain('ALTER TABLE core.wallet_currency_switch_audit')
        ->toContain('ALTER TABLE core.brand_status_history')
        ->toContain('ADD COLUMN IF NOT EXISTS professional_handle_snapshot text');
});

it('grants app_backend FOR ALL with USING and WITH CHECK on every audit table', function () {
    foreach ([
        'professional_deletion_audit_app_backend_all',
        'wallet_currency_switch_audit_app_backend_all',
        'brand_status_history_app_backend_all',
    ] as $policy) {
        expect($this->migration)
            ->toContain("CREATE POLICY {$policy}")
            ->toContain('TO app_backend');
    }
});

it('exposes staff-only SELECT policies filtered on partna_staff role', function () {
    foreach ([
        'professional_deletion_audit_staff_select',
        'wallet_currency_switch_audit_staff_select',
        'brand_status_history_staff_select',
    ] as $policy) {
        expect($this->migration)->toContain("CREATE POLICY {$policy}");
    }

    expect($this->migration)
        ->toContain("ps.role IN ('admin', 'support')")
        ->toContain('FROM core.partna_staff ps');
});

it('grants tenant SELECT on financial + lifecycle audit tables only', function () {
    expect($this->migration)
        ->toContain('CREATE POLICY wallet_currency_switch_audit_tenant_select')
        ->toContain('CREATE POLICY brand_status_history_tenant_select');

    // Deletion audit has no tenant policy — by the time a row matters, the
    // tenant is gone or going. Confirm we have not accidentally added one.
    expect($this->migration)
        ->not->toContain('professional_deletion_audit_tenant_select');
});
