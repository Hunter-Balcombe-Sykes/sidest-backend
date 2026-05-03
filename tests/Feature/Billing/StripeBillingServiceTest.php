<?php

use App\Services\Stripe\StripeBillingService;

it('can be instantiated', function () {
    // StripeBillingService reads config('services.stripe.secret_key') in constructor
    config(['services.stripe.secret_key' => 'sk_test_fake']);

    $service = new StripeBillingService;
    expect($service)->toBeInstanceOf(StripeBillingService::class);
});

it('reuses existing stripe customer id without API call', function () {
    config(['services.stripe.secret_key' => 'sk_test_fake']);

    $professional = new \App\Models\Core\Professional\Professional;
    $professional->stripe_customer_id = 'cus_existing123';

    $service = new StripeBillingService;
    $result = $service->ensureStripeCustomer($professional);

    expect($result)->toBe('cus_existing123');
});
