<?php

namespace Tests\Feature\Professional\AccountDeletion;

use Illuminate\Support\Facades\DB;

// Shared SQLite schema setup for account-deletion feature tests.
// Mirrors the pattern from BrandBootstrapTest — attaches each schema as its own
// in-memory DB so schema-qualified table names (core.*, commerce.*, etc.) resolve.
class AccountDeletionTestCase
{
    public static function boot(): void
    {
        $sqlite = config('database.connections.sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
            'supabase.url' => 'https://test.supabase.co',
            'supabase.service_role_key' => 'test-service-role-key',
            'app.frontend_url' => 'https://app.sidest.test',
            'partna.soft_delete_retention_days' => 30,
        ]);

        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $conn = DB::connection('pgsql');

        foreach (['core', 'commerce', 'notifications', 'billing', 'site'] as $schema) {
            try {
                $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
            } catch (\Throwable) {
            }
        }

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
            id TEXT PRIMARY KEY,
            auth_user_id TEXT,
            handle TEXT,
            handle_lc TEXT,
            display_name TEXT,
            primary_email TEXT,
            professional_type TEXT DEFAULT "professional",
            status TEXT DEFAULT "active",
            onboarding_step INTEGER DEFAULT 0,
            stripe_manual_balance_cents INTEGER DEFAULT 0,
            deletion_token_hash TEXT,
            deletion_requested_at TEXT,
            deletion_confirmed_at TEXT,
            deletion_previous_status TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            professional_handle_snapshot TEXT NOT NULL,
            professional_email_snapshot TEXT NOT NULL,
            event TEXT NOT NULL,
            actor_type TEXT,
            actor_id TEXT,
            actor_handle_snapshot TEXT,
            reason TEXT,
            ip_address TEXT,
            user_agent TEXT,
            metadata TEXT,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.partna_staff (
            id TEXT PRIMARY KEY,
            auth_user_id TEXT,
            role TEXT,
            name TEXT,
            primary_email TEXT,
            phone TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            provider TEXT,
            access_token TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
            id TEXT PRIMARY KEY,
            brand_professional_id TEXT,
            affiliate_professional_id TEXT,
            status TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.brand_commission_topups (
            id TEXT PRIMARY KEY,
            brand_professional_id TEXT,
            status TEXT,
            amount_cents INTEGER,
            created_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS core.customers (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.services (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            subdomain TEXT,
            is_published INTEGER,
            settings TEXT,
            deleted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
            id TEXT PRIMARY KEY,
            site_id TEXT,
            professional_id TEXT,
            pool TEXT,
            path TEXT,
            media_type TEXT,
            processing_state TEXT,
            is_active INTEGER,
            deleted_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
            id TEXT PRIMARY KEY,
            professional_id TEXT,
            stripe_subscription_id TEXT,
            status TEXT,
            cancel_at_period_end INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT
        )');
    }
}
