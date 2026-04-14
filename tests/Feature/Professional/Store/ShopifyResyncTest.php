<?php

use App\Http\Controllers\Api\Professional\Store\ShopifyResyncController;
use App\Jobs\Shopify\SyncShopifyBrandLogoJob;
use App\Jobs\Shopify\SyncShopifyThemeTokensJob;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyDataResyncService;
use App\Services\Shopify\ShopProfileAutoFillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    $conn = DB::connection('pgsql');

    foreach (['core', 'site', 'brand'] as $schema) {
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
        first_name TEXT,
        last_name TEXT,
        primary_email TEXT,
        phone TEXT,
        professional_type TEXT DEFAULT "professional",
        status TEXT DEFAULT "active",
        country_code TEXT,
        timezone TEXT,
        location_street_address TEXT,
        location_city TEXT,
        location_state TEXT,
        location_postcode TEXT,
        location_country TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY,
        professional_id TEXT UNIQUE,
        business_website TEXT,
        setup_complete INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        provider_metadata TEXT,
        shopify_shop_domain TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    RateLimiter::clear('shopify-resync:int-resync-1');
})->group('shopify-resync');

function resyncFakeShopPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 123456,
        'name' => 'Fresh Brand Name',
        'email' => 'fresh@shop.example',
        'phone' => '+61400000000',
        'address1' => '100 Fresh Street',
        'city' => 'Sydney',
        'province' => 'NSW',
        'zip' => '2000',
        'country_name' => 'Australia',
        'country_code' => 'AU',
        'iana_timezone' => 'Australia/Sydney',
        'domain' => 'freshbrand.myshopify.com',
        'currency' => 'AUD',
    ], $overrides);
}

/**
 * Create a brand + empty brand_profile + integration and return all three.
 * The integration is created via the Eloquent model so access_token encryption casts fire.
 */
function createResyncBrand(?array $snapshot = null, array $professionalAttrs = [], array $brandAttrs = []): array
{
    $brandId = 'brand-resync-1';
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $brandId,
        'handle' => 'resyncbrand',
        'handle_lc' => 'resyncbrand',
        'display_name' => 'Original Name',
        'primary_email' => 'original@example.com',
        'phone' => '+61411111111',
        'location_street_address' => '1 Original Rd',
        'location_city' => 'Melbourne',
        'location_state' => 'VIC',
        'location_postcode' => '3000',
        'location_country' => 'Australia',
        'country_code' => 'AU',
        'timezone' => 'Australia/Melbourne',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $professionalAttrs));

    DB::connection('pgsql')->table('brand.brand_profiles')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'business_website' => 'original.example.com',
        'setup_complete' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $brandAttrs));

    $metadata = [
        'shop_domain' => 'resyncbrand.myshopify.com',
        'shop_currency' => 'USD',
    ];
    if ($snapshot !== null) {
        $metadata['shopify_shop_snapshot'] = $snapshot;
    }

    $integration = new ProfessionalIntegration([
        'professional_id' => $brandId,
        'provider' => 'shopify',
        'external_account_id' => 'resyncbrand.myshopify.com',
        'access_token' => 'shpat_test_token',
        'provider_metadata' => $metadata,
    ]);
    $integration->id = 'int-resync-1';
    $integration->save();

    $brand = Professional::find($brandId);
    $brandProfile = BrandProfile::where('professional_id', $brandId)->first();

    return [$brand, $brandProfile, $integration];
}

function buildMatchingSnapshot(): array
{
    // A snapshot whose values exactly match what createResyncBrand() seeded into the DB —
    // so on resync, every field is treated as "Shopify-owned" and therefore updateable.
    return [
        'display_name' => 'Original Name',
        'primary_email' => 'original@example.com',
        'phone' => '+61411111111',
        'location_street_address' => '1 Original Rd',
        'location_city' => 'Melbourne',
        'location_state' => 'VIC',
        'location_postcode' => '3000',
        'location_country' => 'Australia',
        'country_code' => 'AU',
        'timezone' => 'Australia/Melbourne',
        'business_website' => 'original.example.com',
        'shop_currency' => 'USD',
    ];
}

function makeResyncRequest(string $type = 'brand', ?Professional $pro = null): Request
{
    if ($pro === null) {
        $pro = new Professional([
            'professional_type' => $type,
            'status' => 'active',
        ]);
        // id is not fillable on Professional; set it directly so $pro->id works.
        $pro->id = 'brand-resync-1';
    }

    $request = Request::create('/api/store/shopify/resync', 'POST');
    $request->attributes->set('professional', $pro);

    return $request;
}

// ---------- ShopProfileAutoFillService::resyncFromShopData unit tests ----------

it('updates Shopify-sourced fields when DB matches snapshot', function () {
    [$brand, $brandProfile, $integration] = createResyncBrand(buildMatchingSnapshot());

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    // Every field was "clean" (equal to snapshot) → all got updated.
    expect($result['updated'])->toContain('display_name', 'primary_email', 'phone', 'business_website', 'shop_currency');
    expect($result['preserved'])->toBeEmpty();

    $brand->refresh();
    expect($brand->display_name)->toBe('Fresh Brand Name');
    expect($brand->primary_email)->toBe('fresh@shop.example');
    expect($brand->phone)->toBe('+61400000000');
    expect($brand->location_city)->toBe('Sydney');

    $brandProfile->refresh();
    expect($brandProfile->business_website)->toBe('freshbrand.myshopify.com');

    $integration->refresh();
    $metadata = $integration->provider_metadata;
    expect($metadata['shop_currency'])->toBe('AUD');
});

it('preserves manually edited fields when DB differs from snapshot', function () {
    $snapshot = buildMatchingSnapshot();
    // Change only display_name in the DB — user edited it. Snapshot still has the original.
    [$brand, $brandProfile, $integration] = createResyncBrand($snapshot, [
        'display_name' => 'User Edited Name',
    ]);

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    expect($result['preserved'])->toContain('display_name');
    expect($result['updated'])->not->toContain('display_name');

    $brand->refresh();
    expect($brand->display_name)->toBe('User Edited Name');
    // Untouched fields should still sync.
    expect($brand->phone)->toBe('+61400000000');
});

it('writes a fresh snapshot even when all fields were preserved', function () {
    $snapshot = buildMatchingSnapshot();
    // Make every professional field diverge from snapshot → all preserved.
    [, , $integration] = createResyncBrand($snapshot, [
        'display_name' => 'X',
        'primary_email' => 'x@x.x',
        'phone' => 'x',
        'location_street_address' => 'x',
        'location_city' => 'x',
        'location_state' => 'x',
        'location_postcode' => 'x',
        'location_country' => 'x',
        'country_code' => 'XX',
        'timezone' => 'X',
    ], [
        'business_website' => 'user.edited.site',
    ]);

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    expect($result['updated'])->not->toContain('display_name');

    $integration->refresh();
    $newSnapshot = $integration->provider_metadata['shopify_shop_snapshot'];
    expect($newSnapshot['display_name'])->toBe('Fresh Brand Name');
    expect($newSnapshot['business_website'])->toBe('freshbrand.myshopify.com');
    expect($newSnapshot['shop_currency'])->toBe('AUD');
});

it('handles integrations with no prior snapshot by conservatively preserving non-empty fields', function () {
    [$brand, $brandProfile, $integration] = createResyncBrand(null);

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    // Every seeded DB value was non-empty → conservatively preserved.
    expect($result['preserved'])->toContain('display_name', 'primary_email', 'phone', 'business_website');
    expect($result['updated'])->toBeEmpty();

    // But a snapshot is still written, so the next resync has a baseline.
    $integration->refresh();
    expect($integration->provider_metadata['shopify_shop_snapshot']['display_name'])->toBe('Fresh Brand Name');
});

it('fills empty fields from Shopify when there is no prior snapshot', function () {
    // No snapshot + DB has empty phone → phone should be filled.
    [$brand, , $integration] = createResyncBrand(null, ['phone' => null]);

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    expect($result['updated'])->toContain('phone');
    $brand->refresh();
    expect($brand->phone)->toBe('+61400000000');
});

// ---------- ShopifyDataResyncService integration tests ----------

it('dispatches logo and theme jobs and records last_resynced_at', function () {
    Bus::fake([
        SyncShopifyBrandLogoJob::class,
        SyncShopifyThemeTokensJob::class,
    ]);

    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => resyncFakeShopPayload()], 200),
    ]);

    [$brand, , $integration] = createResyncBrand(buildMatchingSnapshot());

    $service = app(ShopifyDataResyncService::class);
    $result = $service->resync($integration);

    Bus::assertDispatched(SyncShopifyBrandLogoJob::class);
    Bus::assertDispatched(SyncShopifyThemeTokensJob::class);

    expect($result['jobs_dispatched'])->toBe(['logo', 'theme_tokens']);
    expect($result['last_resynced_at'])->toBeString();

    $integration->refresh();
    expect($integration->provider_metadata['last_resynced_at'])->toBe($result['last_resynced_at']);
});

it('throws when Shopify API call fails so DB is never partially written', function () {
    Bus::fake();
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response('boom', 503),
    ]);

    [$brand, , $integration] = createResyncBrand(buildMatchingSnapshot());

    $service = app(ShopifyDataResyncService::class);
    expect(fn () => $service->resync($integration))->toThrow(RuntimeException::class);

    Bus::assertNotDispatched(SyncShopifyBrandLogoJob::class);
    Bus::assertNotDispatched(SyncShopifyThemeTokensJob::class);

    // DB was untouched.
    $brand->refresh();
    expect($brand->display_name)->toBe('Original Name');
});

// ---------- ShopifyResyncController HTTP-layer tests ----------

it('rejects non-brand users with 403', function () {
    $controller = app(ShopifyResyncController::class);
    $response = $controller(makeResyncRequest('influencer'));

    expect($response->status())->toBe(403);
});

it('returns 404 when the brand has no Shopify integration', function () {
    // Seed just a brand row, no integration.
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => 'brand-no-integration',
        'handle' => 'noint',
        'handle_lc' => 'noint',
        'display_name' => 'No Integration',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $pro = new Professional([
        'professional_type' => 'brand',
        'status' => 'active',
    ]);
    $pro->id = 'brand-no-integration';

    $controller = app(ShopifyResyncController::class);
    $response = $controller(makeResyncRequest('brand', $pro));

    expect($response->status())->toBe(404);
});

it('rate limits to 1 request per 60 seconds per integration', function () {
    Bus::fake();
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => resyncFakeShopPayload()], 200),
    ]);

    [, , $integration] = createResyncBrand(buildMatchingSnapshot());

    $controller = app(ShopifyResyncController::class);

    // First call succeeds
    $first = $controller(makeResyncRequest());
    expect($first->status())->toBe(200);

    // Second call immediately after is rate-limited
    $second = $controller(makeResyncRequest());
    expect($second->status())->toBe(429);
    expect($second->headers->get('Retry-After'))->not->toBeNull();
});

it('returns 502 when Shopify API fails', function () {
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response('boom', 503),
    ]);

    [, , $integration] = createResyncBrand(buildMatchingSnapshot());

    $controller = app(ShopifyResyncController::class);
    $response = $controller(makeResyncRequest());

    expect($response->status())->toBe(502);
});

it('does not leak access tokens or snapshot contents in the response', function () {
    Bus::fake();
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => resyncFakeShopPayload()], 200),
    ]);

    [, , $integration] = createResyncBrand(buildMatchingSnapshot());

    $controller = app(ShopifyResyncController::class);
    $response = $controller(makeResyncRequest());

    $body = $response->getData(true);
    $json = json_encode($body);

    expect($body)->toHaveKeys(['fields_updated', 'fields_preserved', 'jobs_dispatched', 'last_resynced_at']);
    expect($body)->not->toHaveKey('shopify_shop_snapshot');
    expect($json)->not->toContain('shpat_test_token');
    expect($json)->not->toContain('resyncbrand.myshopify.com');
});
