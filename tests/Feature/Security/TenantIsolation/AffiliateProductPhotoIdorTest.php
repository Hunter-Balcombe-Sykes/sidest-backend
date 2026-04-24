<?php

use App\Http\Controllers\Api\Professional\Store\AffiliateProductPhotoController;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupMediaTables();
    Storage::fake('media');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT,
        brand_professional_id TEXT,
        shopify_product_gid TEXT,
        sort_order INTEGER DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    )');

    $cacheService = Mockery::mock(SiteCacheService::class);
    $cacheService->shouldReceive('invalidateSite')->andReturnNull();
    app()->instance(SiteCacheService::class, $cacheService);
});

it('rejects index for a gid the affiliate has not selected', function () {
    [$affA, $affB] = createTwoTenants('affiliate');

    // Only affB has a selection for this GID
    DB::connection('pgsql')->table('commerce.affiliate_product_selections')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affB->id,
        'brand_professional_id' => (string) Str::uuid(),
        'shopify_product_gid' => 'gid://shopify/Product/12345',
    ]);

    $req = tenantRequestAs($affA);
    $controller = app(AffiliateProductPhotoController::class);

    $response = $controller->index($req, 'gid://shopify/Product/12345');

    expect($response->status())->toBe(404);
});

it('rejects destroy for a gid the affiliate has not selected', function () {
    [$affA, $affB] = createTwoTenants('affiliate');

    DB::connection('pgsql')->table('commerce.affiliate_product_selections')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affB->id,
        'brand_professional_id' => (string) Str::uuid(),
        'shopify_product_gid' => 'gid://shopify/Product/12345',
    ]);

    // Insert a media record for affA so destroy has something to hit if the GID check were absent
    $mediaId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $affA->site->id,
        'professional_id' => $affA->id,
        'pool' => SiteMedia::POOL_PRODUCT,
        'product_gid' => 'gid://shopify/Product/12345',
        'path' => 'img/x.jpg',
        'is_active' => 1,
        'media_type' => 'image',
        'processing_state' => 'ready',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $req = tenantRequestAs($affA);
    $controller = app(AffiliateProductPhotoController::class);

    $response = $controller->destroy($req, 'gid://shopify/Product/12345', $mediaId);

    expect($response->status())->toBe(404);
});

it('rejects reorder for a gid the affiliate has not selected', function () {
    [$affA, $affB] = createTwoTenants('affiliate');

    DB::connection('pgsql')->table('commerce.affiliate_product_selections')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affB->id,
        'brand_professional_id' => (string) Str::uuid(),
        'shopify_product_gid' => 'gid://shopify/Product/12345',
    ]);

    $req = tenantRequestAs($affA, ['ids' => []]);
    $controller = app(AffiliateProductPhotoController::class);

    $response = $controller->reorder($req, 'gid://shopify/Product/12345');
    expect($response->getStatusCode())->toBe(404);
});
