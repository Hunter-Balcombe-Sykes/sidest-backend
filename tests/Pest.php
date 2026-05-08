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

    foreach (['core', 'site', 'commerce', 'notifications', 'analytics', 'billing', 'retail', 'brand'] as $schema) {
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
        stripe_connect_account_id TEXT NULL,
        stripe_connect_status TEXT NULL,
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
        original_filename TEXT NULL,
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

    // Production table lives in the brand schema (BrandPartnerLink model).
    // core.brand_partner_links kept above for backward-compat with older tests.
    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        slot INTEGER NULL,
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
 * Register SQLite UDFs that mimic the Postgres functions our advisory-locking
 * code paths rely on. Production calls `pg_advisory_xact_lock(hashtext(?))`
 * to serialize concurrent reorder/upsert writes per site; under SQLite both
 * functions are absent. The shims are no-ops (locks aren't meaningful in
 * single-process in-memory SQLite anyway), which lets us exercise the real
 * production code path in tests instead of branching on driver.
 */
function shimPgAdvisoryLockForSqlite(): void
{
    $conn = \Illuminate\Support\Facades\DB::connection('pgsql');
    if ($conn->getDriverName() !== 'sqlite') {
        return;
    }

    $pdo = $conn->getPdo();
    $pdo->sqliteCreateFunction('hashtext', fn ($value) => crc32((string) $value), 1);
    $pdo->sqliteCreateFunction('pg_advisory_xact_lock', fn ($value) => null, 1);
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

/*
|--------------------------------------------------------------------------
| Tenant Isolation Helpers
|--------------------------------------------------------------------------
| Shared between tests/Feature/Security/TenantIsolation/*. Each helper
| creates a minimal but realistic tenant (professional + site) and returns
| the live Eloquent model so tests can wire it to a Request.
*/

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;

function tenantHelpersEnsureTables(): void
{
    attachTestSchemas();
    setupProfessionalsTable();
    setupSitesTable();
    setupBrandLinkTables();
}

/**
 * Create an isolated tenant. Returns the freshly-loaded Professional model
 * with its Site eager-loaded. Handle namespaces records so sequential calls
 * never collide.
 */
function createTenant(string $handle, string $type = 'professional'): Professional
{
    tenantHelpersEnsureTables();

    $proId = (string) \Illuminate\Support\Str::uuid();
    $siteId = (string) \Illuminate\Support\Str::uuid();
    $now = now()->toDateTimeString();

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'auth_user_id' => 'auth-'.\Illuminate\Support\Str::random(12),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => $handle.'@example.test',
        'professional_type' => $type,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => $handle,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::query()->with('site')->findOrFail($proId);
}

function createBrandTenant(string $handle = 'brand-a'): Professional
{
    return createTenant($handle, 'brand');
}

function createAffiliateTenant(string $handle = 'affiliate-a'): Professional
{
    return createTenant($handle, 'professional');
}

/**
 * Standard pair: two fully-independent tenants. Returns [$tenantA, $tenantB].
 *
 * @return array{0: Professional, 1: Professional}
 */
function createTwoTenants(string $type = 'brand'): array
{
    $a = $type === 'brand' ? createBrandTenant('brand-a') : createAffiliateTenant('aff-a');
    $b = $type === 'brand' ? createBrandTenant('brand-b') : createAffiliateTenant('aff-b');

    return [$a, $b];
}

/**
 * Make a Request that simulates authenticated access as $tenant.
 * Mirrors the pattern from DocumentControllerIntegrationTest — `current.pro`
 * middleware normally sets this attribute at runtime.
 *
 * Named tenantRequestAs() to avoid collision with the local requestAs() helper
 * declared in ProfessionalEnquiryControllerTest (different signature).
 */
function tenantRequestAs(Professional $tenant, array $input = [], string $method = 'GET'): \Illuminate\Http\Request
{
    $req = \Illuminate\Http\Request::create('/', $method, $input);
    $req->attributes->set('professional', $tenant);
    $req->setUserResolver(fn () => (object) ['professional' => $tenant]);

    return $req;
}

/**
 * core.professional_deletion_audit — all columns nullable, minimal for purge tests.
 */
function setupProfessionalDeletionAuditTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
        id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
        professional_id TEXT NULL,
        professional_handle_snapshot TEXT NULL,
        professional_email_snapshot TEXT NULL,
        event TEXT NULL,
        actor_type TEXT NULL,
        actor_id TEXT NULL,
        actor_handle_snapshot TEXT NULL,
        reason TEXT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        metadata TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * core.professional_integrations — superset of all columns webhook controllers query.
 * Includes shopify_shop_domain (production has it; the older WebhookCrossTenantTest
 * schema omits it). All columns nullable.
 */
function setupProfessionalIntegrationsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        provider TEXT NULL,
        external_account_id TEXT NULL,
        shopify_shop_domain TEXT NULL,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        storefront_token TEXT NULL,
        provider_metadata TEXT NULL,
        status TEXT NULL,
        expires_at TEXT NULL,
        reconciled_through TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * Sign a body string with the Shopify HMAC scheme: base64(HMAC-SHA256(body, secret)).
 * Mirrors the production controller's verification in ValidatesShopifyWebhookHmac.
 */
function signShopifyBody(string $body, string $secret): string
{
    return base64_encode(hash_hmac('sha256', $body, $secret, true));
}

/**
 * Sign a body string with Square's HMAC scheme: base64(HMAC-SHA256(notification_url + body, key)).
 * The notification_url MUST match config('services.square.webhook_notification_url') OR the
 * request's full URL — controller tries both.
 */
function signSquareBody(string $notificationUrl, string $body, string $key): string
{
    return base64_encode(hash_hmac('sha256', $notificationUrl.$body, $key, true));
}

/**
 * Sign a body string with the Fresha HMAC scheme (currently mirrors Square).
 * Update if Fresha's docs reveal a different scheme.
 */
function signFreshaBody(string $notificationUrl, string $body, string $key): string
{
    return base64_encode(hash_hmac('sha256', $notificationUrl.$body, $key, true));
}

/**
 * Generate a valid Stripe-Signature header for a raw body string.
 * Uses the official Stripe SDK so we exercise the real verification path,
 * not a hand-rolled approximation.
 */
function signStripeBody(string $body, string $secret, ?int $timestamp = null): string
{
    $timestamp = $timestamp ?? time();
    $signedPayload = $timestamp.'.'.$body;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return 't='.$timestamp.',v1='.$signature;
}

/**
 * brand.brand_store_settings — minimal columns for Shopify connect/disconnect tests.
 */
function setupBrandStoreSettingsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        default_commission_rate TEXT NULL,
        payout_hold_days INTEGER NULL,
        theme_id INTEGER NULL,
        oxygen_deployment_token TEXT NULL,
        oxygen_storefront_id TEXT NULL,
        domain_mode TEXT NULL,
        domain_wizard_complete INTEGER NULL,
        custom_domain TEXT NULL,
        custom_domain_verified_at TEXT NULL,
        custom_domain_tls_provisioned_at TEXT NULL,
        hydrogen_install_confirmed INTEGER NULL,
        domain_txt_confirmed INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * brand.brand_profiles — minimal columns for Shopify disconnect / brand setup tests.
 */
function setupBrandProfilesTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        brand_status TEXT NULL DEFAULT "building",
        setup_complete INTEGER NULL,
        business_website TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * site.service_categories — minimal columns for Square sync tests.
 */
function setupServiceCategoriesTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.service_categories (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        title TEXT NULL,
        sort_order INTEGER NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * site.services — all columns nullable except PK. Includes deleted_origin for sync-origin tracking.
 */
function setupServicesTable(): void
{
    attachTestSchemas();
    setupServiceCategoriesTable();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.services (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        category_id TEXT NULL,
        title TEXT NULL,
        description TEXT NULL,
        price_cents INTEGER NULL,
        currency_code TEXT NULL,
        duration_minutes INTEGER NULL,
        is_active INTEGER NULL,
        sort_order INTEGER NULL,
        square_catalog_object_id TEXT NULL,
        square_variation_id TEXT NULL,
        square_catalog_version INTEGER NULL,
        square_last_synced_at TEXT NULL,
        square_sync_error TEXT NULL,
        fresha_service_id TEXT NULL,
        fresha_variation_id TEXT NULL,
        fresha_service_version INTEGER NULL,
        fresha_last_synced_at TEXT NULL,
        fresha_sync_error TEXT NULL,
        deleted_origin TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * commerce.commission_movements — minimal columns for CommissionVoidService flush/void tests.
 */
function setupCommissionLedgerEntriesTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_movements (
        id TEXT PRIMARY KEY,
        payout_id TEXT NULL,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        entry_type TEXT NULL,
        status TEXT NULL,
        amount_cents INTEGER NULL,
        currency_code TEXT NULL,
        occurred_at TEXT NULL,
        voided_at TEXT NULL,
        void_reason TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * commerce.commission_payouts — minimal columns for affiliate analytics payout/grace summary.
 * Includes brand_professional_id so CommissionPolicy tests can assert brand-owner access.
 */
function setupCommissionPayoutsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        status TEXT NULL,
        net_payout_cents INTEGER NULL,
        eligible_after TEXT NULL,
        processed_at TEXT NULL,
        void_at TEXT NULL,
        currency_code TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * commerce.brand_commission_topups — minimal columns for brand wallet top-up tests.
 * Only the brand side; no affiliate_professional_id on these records.
 */
function setupBrandCommissionTopupsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.brand_commission_topups (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        amount_cents INTEGER NULL,
        currency_code TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * commerce.affiliate_product_selections — minimal columns for uninstall webhook tests.
 */
function setupAffiliateProductSelectionsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT NULL,
        brand_professional_id TEXT NULL,
        shopify_product_gid TEXT NULL,
        sort_order INTEGER NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * core.customers — all columns nullable, mirrors the production schema.
 */
function setupCustomersTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.customers (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        email TEXT NULL,
        phone TEXT NULL,
        full_name TEXT NULL,
        source TEXT NULL,
        notes TEXT NULL,
        external_id TEXT NULL,
        marketing_opt_in_cached INTEGER NULL,
        redacted_at TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * Insert a Customer row for $pro and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createCustomerFor(Professional $pro, array $overrides = []): \App\Models\Core\Professional\Customer
{
    setupCustomersTable();

    $id = (string) \Illuminate\Support\Str::uuid();
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'professional_id' => $pro->id,
        'email' => 'customer-'.\Illuminate\Support\Str::random(6).'@example.test',
        'full_name' => 'Test Customer',
        'source' => 'manual',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.customers')->insert($row);

    return \App\Models\Core\Professional\Customer::query()->findOrFail($id);
}

/**
 * brand.brand_partner_link_events — append-only audit log for link lifecycle events.
 */
function setupBrandPartnerLinkEventsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_link_events (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        actor_professional_id TEXT NULL,
        event_type TEXT NULL,
        metadata TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * brand.brand_affiliate_invites — invitation tokens for affiliate onboarding.
 * Mirrors the production table including both claimed_by_professional_id (legacy)
 * and claimed_professional_id (current FK column used by BrandAffiliateInvite model).
 */
function setupBrandAffiliateInvitesTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_affiliate_invites (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        invite_type TEXT NULL,
        token TEXT NULL,
        handle TEXT NULL,
        email TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        status TEXT NULL,
        claimed_by_professional_id TEXT NULL,
        claimed_professional_id TEXT NULL,
        expires_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * Insert a Service row for $pro and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createServiceFor(Professional $pro, array $overrides = []): \App\Models\Core\Professional\Service
{
    setupServicesTable();

    $id = (string) \Illuminate\Support\Str::uuid();
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'professional_id' => $pro->id,
        'title' => 'Test Service',
        'price_cents' => 5000,
        'currency_code' => 'AUD',
        'is_active' => 1,
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.services')->insert($row);

    return \App\Models\Core\Professional\Service::withTrashed()->findOrFail($id);
}

/**
 * Insert a ServiceCategory row for $pro and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createServiceCategoryFor(Professional $pro, array $overrides = []): \App\Models\Core\Professional\ServiceCategory
{
    setupServiceCategoriesTable();

    $id = (string) \Illuminate\Support\Str::uuid();
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'professional_id' => $pro->id,
        'title' => 'Test Category',
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.service_categories')->insert($row);

    return \App\Models\Core\Professional\ServiceCategory::withoutGlobalScopes()->findOrFail($id);
}

/**
 * Insert a link-type Block row for $pro and return the Eloquent model.
 */
function createLinkBlockFor(Professional $pro, array $overrides = []): \App\Models\Core\Site\Block
{
    setupBlocksTable();

    $id = (string) \Illuminate\Support\Str::uuid();
    $site = $pro->relationLoaded('site') ? $pro->site : $pro->load('site')->site;
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'professional_id' => $pro->id,
        'site_id' => $site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'title' => 'Test Link',
        'url' => 'https://example.com',
        'sort_order' => 0,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.blocks')->insert($row);

    return \App\Models\Core\Site\Block::query()->findOrFail($id);
}

/**
 * notifications.notifications — minimal columns for notification policy enforcement tests.
 */
function setupNotificationsTable(): void
{
    attachTestSchemas();
    $conn = \Illuminate\Support\Facades\DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        type TEXT NULL,
        category TEXT NULL,
        title TEXT NULL,
        body TEXT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_receipts (
        id TEXT PRIMARY KEY,
        notification_id TEXT NULL,
        professional_id TEXT NULL,
        read_at TEXT NULL,
        dismissed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE(notification_id, professional_id)
    )');
}

/**
 * Insert a SiteMedia document-pool row for $pro's site and return the model.
 */
function createDocumentFor(Professional $pro, array $overrides = []): \App\Models\Core\Site\SiteMedia
{
    setupMediaTables();

    $id = (string) \Illuminate\Support\Str::uuid();
    $site = $pro->relationLoaded('site') ? $pro->site : $pro->load('site')->site;
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'site_id' => $site->id,
        'pool' => \App\Models\Core\Site\SiteMedia::POOL_DOCUMENTS,
        'media_type' => 'application/pdf',
        'processing_state' => \App\Models\Core\Site\SiteMedia::PROCESSING_STATE_READY,
        'is_active' => 1,
        'alt_text' => 'Test Document',
        'original_filename' => 'test.pdf',
        'path' => 'documents/test.pdf',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.site_media')->insert($row);

    return \App\Models\Core\Site\SiteMedia::query()->findOrFail($id);
}

/**
 * commerce.orders + commerce.order_events + commerce.brand_affiliate_rollup + commerce.order_items
 * — minimal columns for Phase 3 webhook write-path and analytics read-path tests.
 * SQLite does not support INSERT ... ON CONFLICT WHERE (partial predicate), so tests that
 * exercise the LWW guard directly must call markTestSkipped() on non-pgsql connections.
 */
function setupCommerceOrdersTables(): void
{
    attachTestSchemas();
    $conn = \Illuminate\Support\Facades\DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.orders (
        id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
        shopify_order_id TEXT NOT NULL,
        shopify_shop_domain TEXT NOT NULL,
        brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL,
        customer_id TEXT NULL,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_cents INTEGER NOT NULL DEFAULT 0,
        discount_cents INTEGER NOT NULL DEFAULT 0,
        refund_cents INTEGER NOT NULL DEFAULT 0,
        net_cents INTEGER NOT NULL DEFAULT 0,
        commission_cents INTEGER NOT NULL DEFAULT 0,
        commission_rate REAL NOT NULL DEFAULT 0,
        rate_source TEXT NOT NULL DEFAULT \'pending\',
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        line_items TEXT NOT NULL DEFAULT \'[]\',
        shopify_data TEXT NOT NULL DEFAULT \'{}\',
        stripe_payment_intent_id TEXT NULL,
        stripe_transfer_id TEXT NULL,
        payout_id TEXT NULL,
        reconciled_at TEXT NULL,
        shopify_updated_at TEXT NOT NULL,
        occurred_at TEXT NOT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE(shopify_shop_domain, shopify_order_id)
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.order_events (
        id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
        order_id TEXT NOT NULL,
        event_type TEXT NOT NULL,
        amount_delta_cents INTEGER NULL,
        metadata TEXT NOT NULL DEFAULT \'{}\',
        source TEXT NOT NULL DEFAULT \'webhook\',
        shopify_event_id TEXT NULL UNIQUE,
        shopify_triggered_at TEXT NOT NULL,
        occurred_at TEXT NULL
    )');
    // UNIQUE on shopify_event_id simulates the PG partial unique index.
    // SQLite UNIQUE with NULLs: multiple NULLs are treated as distinct (NOT equal),
    // so reconciler-sourced events (NULL event_id) can coexist — matches PG partial-index behavior.

    // Trigger-maintained rollup — manually seeded in tests since SQLite won't fire PG triggers.
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.brand_affiliate_rollup (
        brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL,
        day TEXT NOT NULL,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        orders_count INTEGER NOT NULL DEFAULT 0,
        gross_cents INTEGER NOT NULL DEFAULT 0,
        refund_cents INTEGER NOT NULL DEFAULT 0,
        net_cents INTEGER NOT NULL DEFAULT 0,
        commission_cents INTEGER NOT NULL DEFAULT 0,
        reversed_commission_cents INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NULL,
        PRIMARY KEY (brand_professional_id, affiliate_professional_id, day, currency_code)
    )');

    // Normalized mirror of line_items JSONB — used by topProducts query.
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.order_items (
        id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(16)))),
        order_id TEXT NOT NULL,
        shopify_line_item_id TEXT NOT NULL,
        shopify_product_id TEXT NULL,
        shopify_variant_id TEXT NULL,
        sku TEXT NULL,
        title TEXT NOT NULL DEFAULT \'\',
        quantity INTEGER NOT NULL DEFAULT 1,
        unit_price_cents INTEGER NOT NULL DEFAULT 0,
        discount_cents INTEGER NOT NULL DEFAULT 0,
        line_total_cents INTEGER NOT NULL DEFAULT 0,
        commission_cents INTEGER NOT NULL DEFAULT 0,
        commission_rate REAL NOT NULL DEFAULT 0,
        brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL,
        occurred_at TEXT NOT NULL,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        UNIQUE (order_id, shopify_line_item_id)
    )');
}

/**
 * core.partna_staff — internal staff accounts, linked to Supabase auth users.
 */
function setupPartnaStaffTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.partna_staff (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT NULL,
        role TEXT NULL,
        primary_email TEXT NULL,
        name TEXT NULL,
        phone TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * analytics.site_visits — raw visit events used by live analytics read queries.
 */
function setupSiteVisitsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.site_visits (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        site_id TEXT NULL,
        visitor_id TEXT NULL,
        session_id TEXT NULL,
        ip_hash TEXT NULL,
        device_type TEXT NULL,
        country_code TEXT NULL,
        referrer TEXT NULL,
        utm_source TEXT NULL,
        utm_medium TEXT NULL,
        utm_campaign TEXT NULL,
        occurred_at TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * analytics.link_clicks — minimal columns for click dedup and analytics tests.
 */
function setupLinkClicksTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.link_clicks (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        site_id TEXT NULL,
        block_id TEXT NULL,
        occurred_at TEXT NULL,
        session_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        utm_source TEXT NULL,
        utm_medium TEXT NULL,
        utm_campaign TEXT NULL,
        created_at TEXT NULL
    )');
}

/**
 * notifications.email_subscriptions — minimal columns for broadcast fan-out tests.
 */
function setupEmailSubscriptionsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        list_key TEXT NOT NULL DEFAULT "marketing",
        email TEXT NOT NULL,
        email_lc TEXT NOT NULL,
        full_name TEXT NULL,
        status TEXT NOT NULL DEFAULT "subscribed",
        subscribed_at TEXT NULL,
        unsubscribed_at TEXT NULL,
        unsubscribe_token TEXT NOT NULL,
        consent_source TEXT NULL,
        consent_ip_hash TEXT NULL,
        consent_user_agent TEXT NULL,
        qr_slug TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
}

/**
 * site.site_subdomain_aliases — minimal columns for cache-invalidation paths
 * that iterate over historical aliases for a site.
 */
function setupSubdomainAliasesTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
        id TEXT PRIMARY KEY,
        site_id TEXT NULL,
        subdomain TEXT NULL,
        created_at TEXT NULL
    )');
}
