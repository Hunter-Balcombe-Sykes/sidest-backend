<?php

use App\Http\Controllers\Api\Professional\Brand\ShopifyIntegrationController;
use App\Services\Store\BrandAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBrandStoreSettingsTable();
    setupBrandProfilesTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        access_token TEXT,
        provider_metadata TEXT,
        status TEXT,
        expires_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // disconnect() purges affiliate product selections for the brand before deleting the integration row.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        product_gid TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

it('shopify status only reports integration for the caller not another brand', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    // Brand A has a Shopify integration.
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'shopify',
        'access_token' => 'token-a',
        'provider_metadata' => json_encode(['shop_domain' => 'brand-a.myshopify.com', 'shop_id' => '111']),
        'status' => 'connected',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // BrandAccessService: Brand B can only manage their own brand.
    $this->mock(BrandAccessService::class, function ($mock) use ($b) {
        $mock->shouldReceive('isBrandProfessional')->andReturnUsing(fn ($pro) => $pro->id === $b->id);
        $mock->shouldReceive('canManageShopify')->andReturnUsing(
            fn ($pro, $brandId) => $pro->id === $b->id && $brandId === $b->id
        );
    });

    // Brand B calls status() with no brand_professional_id — resolves to Brand B's own ID.
    $req = tenantRequestAs($b);
    $response = app(ShopifyIntegrationController::class)->status($req);
    $payload = $response->getData(true);

    // Brand B is not connected; Brand A's shop domain must not appear.
    expect($payload['data']['connected'] ?? false)->toBeFalse();
    expect($payload['data']['shop_domain'] ?? null)->toBeNull();
    expect($payload['data']['shop_domain'] ?? null)->not->toBe('brand-a.myshopify.com');
});

it('shopify disconnect does not affect another brands integration record', function () {
    [$a, $b] = createTwoTenants('brand');
    $now = now()->toDateTimeString();

    $aIntegrationId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => $aIntegrationId,
        'professional_id' => $a->id,
        'provider' => 'shopify',
        'access_token' => 'token-a',
        'provider_metadata' => json_encode(['shop_domain' => 'brand-a.myshopify.com']),
        'status' => 'connected',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // BrandAccessService: Brand B can only manage their own brand.
    $this->mock(BrandAccessService::class, function ($mock) use ($b) {
        $mock->shouldReceive('isBrandProfessional')->andReturnUsing(fn ($pro) => $pro->id === $b->id);
        $mock->shouldReceive('canManageShopify')->andReturnUsing(
            fn ($pro, $brandId) => $pro->id === $b->id && $brandId === $b->id
        );
    });

    // Brand B calls disconnect — resolves to Brand B's own integration (which doesn't exist).
    $req = tenantRequestAs($b, [], 'POST');
    $response = app(ShopifyIntegrationController::class)->disconnect($req);

    // disconnect() is idempotent — returns 200 with connected:false even when no integration exists.
    // The key assertion is that Brand A's record is unaffected; status 200 is expected.
    expect($response->getStatusCode())->toBe(200);

    // Brand A's integration must be unaffected.
    $aRecord = DB::table('core.professional_integrations')->where('id', $aIntegrationId)->first();
    expect($aRecord->access_token)->toBe('token-a');
});
