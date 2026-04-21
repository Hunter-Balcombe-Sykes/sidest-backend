<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
// Note: Unit tests like MediaJobReliabilityTest opt into Tests\TestCase via
// their own `uses(TestCase::class)->in(__FILE__)` call. Don't add Unit here
// or you'll get "test case can not be used: already uses" clash.

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Schema Bootstrap Helpers
|--------------------------------------------------------------------------
|
| BaseModel forces the 'pgsql' connection on every Eloquent model. In tests
| we redirect 'pgsql' to in-memory SQLite (see TestCase::setUp), but SQLite
| has no real schema support, so we ATTACH DATABASE for each schema name
| and CREATE TABLE under the right "schema". All schema setup must run on
| the 'pgsql' connection explicitly so the model-facing PDO handle sees it.
|
| Each helper is idempotent (CREATE TABLE IF NOT EXISTS, ATTACH wrapped in
| try/catch). Tests call only the helpers they need.
|
*/

/**
 * Attach all schema namespaces the project uses. Safe to call from any
 * test; idempotent within a single PDO connection.
 */
function attachTestSchemas(): void
{
    $conn = \Illuminate\Support\Facades\DB::connection('pgsql');
    if ($conn->getDriverName() !== 'sqlite') {
        return;
    }

    foreach (['core', 'site', 'commerce', 'notifications', 'analytics', 'billing', 'retail'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable $e) {
            // already attached — ignore
        }
    }
}

/**
 * Permissive core.professionals table — every column nullable. Just enough
 * structure for tests that read/write professionals via the model or raw queries.
 */
function setupProfessionalsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT NULL,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        display_name TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        primary_email TEXT NULL,
        phone TEXT NULL,
        professional_type TEXT NULL,
        status TEXT NULL,
        bio TEXT NULL,
        about TEXT NULL,
        country_code TEXT NULL,
        timezone TEXT NULL,
        onboarding_step INTEGER NULL,
        public_contact_number TEXT NULL,
        public_contact_email TEXT NULL,
        icon_bucket TEXT NULL,
        icon_path TEXT NULL,
        headshot_bucket TEXT NULL,
        headshot_path TEXT NULL,
        location_street_address TEXT NULL,
        location_postcode TEXT NULL,
        location_city TEXT NULL,
        location_state TEXT NULL,
        location_country TEXT NULL,
        qr_slug TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * site.sites table — minimal columns, all nullable.
 */
function setupSitesTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        subdomain TEXT NULL,
        subdomain_changed_at TEXT NULL,
        is_published INTEGER NULL,
        settings TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * site.public_site_payload — Postgres view in production, plain table here.
 */
function setupPublicSitePayloadTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.public_site_payload (
        site_id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        subdomain TEXT NULL,
        payload TEXT NULL
    )');
}

/**
 * site.site_media + site.media_variants for media upload / processing tests.
 * (Both tables live under the 'site' schema in production.)
 */
function setupMediaTables(): void
{
    attachTestSchemas();
    $conn = \Illuminate\Support\Facades\DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
        id TEXT PRIMARY KEY,
        site_id TEXT NULL,
        professional_id TEXT NULL,
        pool TEXT NULL,
        path TEXT NULL,
        original_path TEXT NULL,
        original_mime TEXT NULL,
        original_size_bytes INTEGER NULL,
        media_type TEXT NULL,
        processing_state TEXT NULL,
        processing_error TEXT NULL,
        duration_ms INTEGER NULL,
        poster_path TEXT NULL,
        sort_order INTEGER NULL,
        is_active INTEGER NULL,
        product_gid TEXT NULL,
        alt_text TEXT NULL,
        caption TEXT NULL,
        purpose TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

    // media_variants column list mirrors the production migration
    // (supabase/migrations/20260403000000_v2_baseline.sql) — every column
    // nullable here, but all column names match.
    $conn->statement('CREATE TABLE IF NOT EXISTS site.media_variants (
        id TEXT PRIMARY KEY,
        media_id TEXT NULL,
        variant_key TEXT NULL,
        artifact_type TEXT NULL,
        disk TEXT NULL,
        path TEXT NULL,
        mime TEXT NULL,
        width INTEGER NULL,
        height INTEGER NULL,
        bitrate_kbps INTEGER NULL,
        file_size_bytes INTEGER NULL,
        duration_ms INTEGER NULL,
        metadata TEXT NULL,
        content_hash TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * core.brand_partner_links + core.brand_affiliate_invites for brand connection tests.
 */
function setupBrandLinkTables(): void
{
    attachTestSchemas();
    $conn = \Illuminate\Support\Facades\DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.brand_partner_links (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        custom_photos_enabled INTEGER NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.brand_affiliate_invites (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        invite_type TEXT NULL,
        token TEXT NULL,
        handle TEXT NULL,
        email TEXT NULL,
        status TEXT NULL,
        claimed_by_professional_id TEXT NULL,
        expires_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * core.waitlist_signups for waitlist tests.
 */
function setupWaitlistTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.waitlist_signups (
        id TEXT PRIMARY KEY,
        email TEXT NULL,
        professional_type TEXT NULL,
        industry TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        social_handle TEXT NULL,
        company_name TEXT NULL,
        country_code TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * site.blocks — all columns nullable except the PK. Used by backfill command
 * tests and any test that exercises Block Eloquent operations in SQLite.
 */
function setupBlocksTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.blocks (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        site_id TEXT NULL,
        block_group TEXT NULL,
        block_type TEXT NULL,
        title TEXT NULL,
        url TEXT NULL,
        icon_key TEXT NULL,
        sort_order INTEGER NULL,
        is_active INTEGER NULL,
        is_enabled INTEGER NULL,
        settings TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * notifications.notification_preferences for ConfirmationPreferenceServiceTest.
 */
function setupNotificationPreferencesTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_preferences (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        channel TEXT NULL,
        category TEXT NULL,
        enabled INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}
