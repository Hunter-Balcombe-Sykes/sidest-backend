<?php

use App\Services\Store\BrandPricingService;

it('applies discount and rounds up to nearest five cents', function () {
    $service = new BrandPricingService;

    expect($service->discountedPriceCents(101, 10.0))->toBe(95);
    expect($service->discountedPriceCents(100, 10.0))->toBe(90);
    expect($service->discountedPriceCents(99, 50.0))->toBe(50);
});

it('resolves effective commission with override fallback', function () {
    $service = new BrandPricingService;

    expect($service->effectiveCommissionRate(null, 22.4, 15.0, 11.0))->toBe(22.4);
    expect($service->effectiveCommissionRate(null, 17.5, 11.0, null))->toBe(17.5);
    expect($service->effectiveCommissionRate(null, null, 12.5, null))->toBe(12.5);
    expect($service->effectiveCommissionRate(null, null, null, null))->toBe((float) config('comet.store.default_commission_rate', 15));
    expect($service->effectiveCommissionRate(30.0, 17.5, 11.0, null))->toBe(30.0);
    expect($service->effectiveCommissionRate(10.0, 17.5, 11.0, null))->toBe(17.5);
});
