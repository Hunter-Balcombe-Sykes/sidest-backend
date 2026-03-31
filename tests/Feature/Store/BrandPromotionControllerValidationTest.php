<?php

use App\Http\Controllers\Api\Professional\Store\BrandPromotionController;
use App\Models\Retail\BrandPromotion;
use App\Services\Store\BrandAccessService;
use App\Services\Store\BrandPricingService;

it('keeps target arrays unchanged on partial update when scope and ids are omitted', function () {
    $controller = new BrandPromotionController(
        Mockery::mock(BrandAccessService::class),
        new BrandPricingService
    );

    $existing = new BrandPromotion([
        'affiliate_scope' => 'segments',
        'product_scope' => 'products',
    ]);

    $validate = \Closure::bind(function (string $brandId, array $validated, ?BrandPromotion $existing, bool $partial): array {
        return $this->validateTargetOwnership($brandId, $validated, $existing, $partial);
    }, $controller, BrandPromotionController::class);

    [$affiliateIds, $affiliateSegmentIds, $productIds, $error] = $validate(
        'brand-1',
        ['name' => 'unchanged fields'],
        $existing,
        true
    );

    expect($error)->toBeNull();
    expect($affiliateIds)->toBeNull();
    expect($affiliateSegmentIds)->toBeNull();
    expect($productIds)->toBeNull();
});

it('clears incompatible target arrays when scope is explicitly changed', function () {
    $controller = new BrandPromotionController(
        Mockery::mock(BrandAccessService::class),
        new BrandPricingService
    );

    $existing = new BrandPromotion([
        'affiliate_scope' => 'segments',
        'product_scope' => 'products',
    ]);

    $validate = \Closure::bind(function (string $brandId, array $validated, ?BrandPromotion $existing, bool $partial): array {
        return $this->validateTargetOwnership($brandId, $validated, $existing, $partial);
    }, $controller, BrandPromotionController::class);

    [$affiliateIds, $affiliateSegmentIds, $productIds, $error] = $validate(
        'brand-1',
        [
            'affiliate_scope' => 'all',
            'product_scope' => 'all',
        ],
        $existing,
        true
    );

    expect($error)->toBeNull();
    expect($affiliateIds)->toBe([]);
    expect($affiliateSegmentIds)->toBe([]);
    expect($productIds)->toBe([]);
});
