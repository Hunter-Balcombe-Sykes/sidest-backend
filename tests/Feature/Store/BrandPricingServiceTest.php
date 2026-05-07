<?php

use App\Services\Store\BrandPricingService;

it('returns system default commission rate from config', function () {
    $service = new BrandPricingService;

    expect($service->defaultCommissionRate())->toBe((float) config('partna.store.default_commission_rate', 15));
});

it('resolves effective commission with override and default fallback', function () {
    $service = new BrandPricingService;

    expect($service->effectiveCommissionRate(25.0, 15.0))->toBe(25.0);
    expect($service->effectiveCommissionRate(null, 20.0))->toBe(20.0);
    expect($service->effectiveCommissionRate(null, null))->toBe($service->defaultCommissionRate());
});

it('clamps commission rate between 0 and 100', function () {
    $service = new BrandPricingService;

    expect($service->effectiveCommissionRate(150.0, null))->toBe(100.0);
    expect($service->effectiveCommissionRate(-5.0, null))->toBe(0.0);
});
