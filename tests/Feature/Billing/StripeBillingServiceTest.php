<?php

use App\Services\Stripe\StripeBillingService;

it('can be instantiated', function () {
    // StripeBillingService reads config('services.stripe.secret_key') in constructor
    config(['services.stripe.secret_key' => 'sk_test_fake']);

    $service = new StripeBillingService;
    expect($service)->toBeInstanceOf(StripeBillingService::class);
});

// Under v2 Option A, core.professionals.stripe_customer_id is dropped. The v1 "cache the
// customer id on the professional, skip Stripe on subsequent calls" optimisation is gone;
// ensureStripeCustomer now leans on Stripe's idempotency_key window to dedupe within 24h,
// and longer-term tracking lives on billing.subscriptions.stripe_customer_id which is
// written by the subscription-created webhook handler. See StripeIdempotencyKeysTest for
// the deterministic key-format coverage.
it('ensureStripeCustomer always calls Stripe under v2 (cache column dropped)', function () {
    config(['services.stripe.secret_key' => 'sk_test_fake']);

    $professional = new \App\Models\Core\Professional\Professional;
    $professional->id = (string) \Illuminate\Support\Str::uuid();
    $professional->primary_email = 'billing-v2@example.test';
    $professional->display_name = 'V2 Billing Test';

    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts) use ($professional) {
            return ($opts['idempotency_key'] ?? null) === "customer_{$professional->id}"
                && ($params['email'] ?? null) === 'billing-v2@example.test';
        })
        ->andReturn((object) ['id' => 'cus_v2_returned']);

    $stripeClient = Mockery::mock(\Stripe\StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);

    $service = new StripeBillingService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    expect($service->ensureStripeCustomer($professional))->toBe('cus_v2_returned');
});
