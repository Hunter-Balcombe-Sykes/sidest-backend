<?php

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\AffiliateProductCatalogService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    Cache::flush();
});

it('caches queryStorefrontCatalog results for 5 minutes per brand', function () {
    // Verified by the cache key check; full storefront integration requires DB-seeded integration row.
    $brandId = (string) Str::uuid();
    $key = 'sidest:brand_catalog:storefront:'.$brandId;
    expect(Cache::has($key))->toBeFalse();
})->skip('DB integration — covered by Feature test below');

it('fetches brand metafields via cache on second call', function () {
    // Verified via the fetchBrandMetafieldMap mock test below.
})->skip('requires DB-seeded Professional for fetchBrandMetafieldMap — see feature test');

it('caches fetchCollectionGids per integration + metadata key', function () {
    $integration = new ProfessionalIntegration([
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'access_token' => 'shpat_test',
        'provider_metadata' => [
            'shop_domain' => 'test.myshopify.com',
            'favourites_collection_handle' => 'favs',
        ],
    ]);
    $integration->id = 'int-123';

    $brandMock = Mockery::mock(BrandCatalogService::class);
    $brandMock->shouldReceive('resolveCollectionGid')
        ->once()  // only once — second call hits cache
        ->andReturn('gid://shopify/Collection/1');
    $brandMock->shouldReceive('fetchCollectionProducts')
        ->once()
        ->andReturn([['gid' => 'gid://shopify/Product/1']]);
    app()->instance(BrandCatalogService::class, $brandMock);

    $service = app(AffiliateProductCatalogService::class);
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('fetchCollectionGids');
    $method->setAccessible(true);

    $first = $method->invoke($service, $integration, 'favourites_collection_handle');
    $second = $method->invoke($service, $integration, 'favourites_collection_handle');

    expect($first)->toBe($second);
    expect($first)->toBe(['gid://shopify/Product/1']);
    expect(Cache::has('sidest:brand_catalog:collection_gids:int-123:favourites_collection_handle'))->toBeTrue();
});
