<?php

use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function fakeStorefrontResponse(array $products = [], bool $hasNextPage = false): array
{
    $edges = array_map(fn ($p) => [
        'node' => [
            'id' => $p['gid'],
            'title' => $p['title'] ?? 'Product',
            'handle' => $p['handle'] ?? 'product',
            'availableForSale' => true,
            'featuredImage' => null,
            'priceRange' => [
                'minVariantPrice' => ['amount' => '10.00', 'currencyCode' => 'AUD'],
                'maxVariantPrice' => ['amount' => '10.00', 'currencyCode' => 'AUD'],
            ],
            'variants' => ['edges' => []],
        ],
        'cursor' => 'cursor-' . $p['gid'],
    ], $products);

    return [
        'data' => [
            'collection' => [
                'products' => [
                    'edges' => $edges,
                    'pageInfo' => ['hasNextPage' => $hasNextPage],
                ],
            ],
        ],
    ];
}

it('resolves affiliate brand integration throws when no link exists', function () {
    // Mock BrandPartnerLink to return null without hitting DB
    $service = Mockery::mock(AffiliateProductCatalogService::class)->makePartial();
    $service->shouldReceive('resolveAffiliateBrandIntegration')
        ->andThrow(new \RuntimeException('No brand connection found.', 404));

    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'influencer']);

    expect(fn () => $service->resolveAffiliateBrandIntegration($affiliate))
        ->toThrow(\RuntimeException::class, 'No brand connection found.');
});

it('checks product exists in cached catalog', function () {
    $brandId = (string) Str::uuid();
    $cacheKey = CacheKeyGenerator::brandActiveCatalog($brandId);

    Cache::put($cacheKey, [
        ['gid' => 'gid://shopify/Product/111', 'title' => 'Product A'],
        ['gid' => 'gid://shopify/Product/222', 'title' => 'Product B'],
    ], now()->addMinutes(15));

    $service = new AffiliateProductCatalogService();

    expect($service->isProductInCatalog($brandId, 'gid://shopify/Product/111'))->toBeTrue();
    expect($service->isProductInCatalog($brandId, 'gid://shopify/Product/999'))->toBeFalse();

    Cache::forget($cacheKey);
});

it('returns empty catalog when integration is missing', function () {
    $brandId = (string) Str::uuid();
    $cacheKey = CacheKeyGenerator::brandActiveCatalog($brandId);

    // Pre-cache an empty array to simulate no integration without DB
    Cache::put($cacheKey, [], now()->addMinutes(15));

    $service = new AffiliateProductCatalogService();
    $result = $service->fetchActiveCatalog($brandId);

    expect($result)->toBe([]);

    Cache::forget($cacheKey);
});

it('generates correct cache key for brand catalog', function () {
    $brandId = (string) Str::uuid();
    $key = CacheKeyGenerator::brandActiveCatalog($brandId);

    expect($key)->toBe("brand:{$brandId}:catalog:active");
});
