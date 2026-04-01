<?php

use App\Services\Store\PromotionResolutionService;
use App\Services\Store\ShopifyOrderProcessingService;

it('applies promotion rate when cache is prewarmed with a static entry', function () {
    $promotionService = Mockery::mock(PromotionResolutionService::class);
    $promotionService
        ->shouldReceive('resolveActivePromotion')
        ->once()
        ->with('brand-1', 'affiliate-1', 'product-1')
        ->andReturn([
            'commission_rate' => 30.0,
            'discount_rate' => null,
            'promotion_id' => 'promo-1',
            'promotion_name' => 'Promo 1',
        ]);

    $service = new ShopifyOrderProcessingService($promotionService);

    $cacheKey = 'brand-1|affiliate-1|product-1';
    $cache = [
        $cacheKey => [
            'rate' => 15.0,
            'source' => 'brand_default',
            'metadata' => [],
            'is_static' => true,
        ],
    ];

    $resolve = \Closure::bind(function (string $brandId, string $affiliateId, ?string $productId, array &$cache): array {
        return $this->resolveCommissionRate($brandId, $affiliateId, $productId, $cache);
    }, $service, ShopifyOrderProcessingService::class);

    [$rate, $source, $metadata] = $resolve('brand-1', 'affiliate-1', 'product-1', $cache);

    expect($rate)->toBe(30.0);
    expect($source)->toBe('promotion');
    expect($metadata['promotion_id'] ?? null)->toBe('promo-1');
    expect($cache[$cacheKey]['is_static'])->toBeFalse();
});

it('keeps static rate when promotion rate is lower than static cache entry', function () {
    $promotionService = Mockery::mock(PromotionResolutionService::class);
    $promotionService
        ->shouldReceive('resolveActivePromotion')
        ->once()
        ->with('brand-2', 'affiliate-2', 'product-2')
        ->andReturn([
            'commission_rate' => 10.0,
            'discount_rate' => null,
            'promotion_id' => 'promo-2',
            'promotion_name' => 'Promo 2',
        ]);

    $service = new ShopifyOrderProcessingService($promotionService);

    $cacheKey = 'brand-2|affiliate-2|product-2';
    $cache = [
        $cacheKey => [
            'rate' => 15.0,
            'source' => 'brand_default',
            'metadata' => [],
            'is_static' => true,
        ],
    ];

    $resolve = \Closure::bind(function (string $brandId, string $affiliateId, ?string $productId, array &$cache): array {
        return $this->resolveCommissionRate($brandId, $affiliateId, $productId, $cache);
    }, $service, ShopifyOrderProcessingService::class);

    [$rate, $source, $metadata] = $resolve('brand-2', 'affiliate-2', 'product-2', $cache);

    expect($rate)->toBe(15.0);
    expect($source)->toBe('brand_default');
    expect($metadata['bypassed_promotion_id'] ?? null)->toBe('promo-2');
});
