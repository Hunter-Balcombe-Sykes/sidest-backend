<?php

use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Store\AffiliateProductCatalogService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Support\Str;

it('resolves affiliate brand integration throws when no link exists', function () {
    // Use a Mockery partial so we don't need to seed the brand_partner_links table.
    $service = Mockery::mock(AffiliateProductCatalogService::class, [
        Mockery::mock(BrandCatalogService::class),
        Mockery::mock(CacheLockService::class),
    ])->makePartial();

    $service->shouldReceive('resolveAffiliateBrandIntegration')
        ->andThrow(new \RuntimeException('No brand connection found.', 404));

    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'influencer']);

    expect(fn () => $service->resolveAffiliateBrandIntegration($affiliate))
        ->toThrow(\RuntimeException::class, 'No brand connection found.');
});

it('constructs with the BrandCatalogService and CacheLockService dependencies', function () {
    // Master Pattern 14 added CacheLockService as a constructor dep so
    // fetchActiveCatalog uses rememberLocked (single-flight + jitter + SWR).
    // This test guards the signature against future drift.
    $service = new AffiliateProductCatalogService(
        Mockery::mock(BrandCatalogService::class),
        Mockery::mock(CacheLockService::class),
    );

    expect($service)->toBeInstanceOf(AffiliateProductCatalogService::class);
});

it('generates correct cache key for brand catalog', function () {
    $brandId = (string) Str::uuid();
    $key = CacheKeyGenerator::brandActiveCatalog($brandId);

    expect($key)->toBe("brand:{$brandId}:catalog:active");
});
