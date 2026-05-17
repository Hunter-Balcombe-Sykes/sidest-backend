<?php

use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Str;
use Stripe\StripeClient;

beforeEach(function () {
    config(['services.stripe.secret_key' => 'sk_test_fake']);
    attachTestSchemas();
    setupProfessionalsTable();
});

/**
 * Build a real (persisted) professional so Eloquent has a row to update when
 * StripeBillingService writes the customer ID back. Using `new` instead would
 * silently no-op the save().
 */
function billingTest_makeProfessional(): Professional
{
    $id = (string) Str::uuid();

    return Professional::create([
        'id' => $id,
        'handle' => "h-{$id}",
        'handle_lc' => "h-{$id}",
        'display_name' => "Pro {$id}",
        'primary_email' => "{$id}@example.test",
        'professional_type' => 'brand',
        'status' => 'active',
    ]);
}

function billingTest_injectStripeClient(StripeBillingService $service, StripeClient $client): void
{
    $prop = (new ReflectionClass($service))->getProperty('stripe');
    $prop->setAccessible(true);
    $prop->setValue($service, $client);
}

it('can be instantiated', function () {
    expect(new StripeBillingService)->toBeInstanceOf(StripeBillingService::class);
});

it('ensureStripeCustomer creates and persists the customer ID on first call', function () {
    $professional = billingTest_makeProfessional();

    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')
        ->once()
        ->andReturn((object) ['id' => 'cus_first_call']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);

    $service = new StripeBillingService;
    billingTest_injectStripeClient($service, $stripeClient);

    $returned = $service->ensureStripeCustomer($professional);

    expect($returned)->toBe('cus_first_call');
    expect($professional->fresh()->stripe_billing_customer_id)->toBe('cus_first_call');
});

it('ensureStripeCustomer reuses the persisted ID and never calls Stripe again', function () {
    $professional = billingTest_makeProfessional();
    $professional->update(['stripe_billing_customer_id' => 'cus_already_stored']);

    // If reuse works, Stripe is never invoked. Mocking with `shouldNotReceive`
    // makes a regression that bypasses the cache loud instead of silent.
    $customersSpy = Mockery::mock();
    $customersSpy->shouldNotReceive('create');

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);

    $service = new StripeBillingService;
    billingTest_injectStripeClient($service, $stripeClient);

    expect($service->ensureStripeCustomer($professional))->toBe('cus_already_stored');
});

it('ensureStripeCustomer makes exactly one Stripe call across two invocations on a fresh professional', function () {
    $professional = billingTest_makeProfessional();

    // The combined assertion that proves the whole reuse story: across two
    // ensureStripeCustomer() calls on the same fresh professional, Stripe is
    // hit precisely once. First call creates + persists, second call reads
    // the column and short-circuits.
    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')
        ->once()
        ->andReturn((object) ['id' => 'cus_one_and_only']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);

    $service = new StripeBillingService;
    billingTest_injectStripeClient($service, $stripeClient);

    expect($service->ensureStripeCustomer($professional))->toBe('cus_one_and_only');
    expect($service->ensureStripeCustomer($professional->fresh()))->toBe('cus_one_and_only');
});

it('createBillingPortalSession opens a portal for the stored customer', function () {
    $professional = billingTest_makeProfessional();
    $professional->update(['stripe_billing_customer_id' => 'cus_portal_test']);

    $customersSpy = Mockery::mock();
    $customersSpy->shouldNotReceive('create');

    $portalSessionsSpy = Mockery::mock();
    $portalSessionsSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params) {
            expect($params['customer'])->toBe('cus_portal_test');
            expect($params['return_url'])->toBe('https://example.test/return');

            return true;
        })
        ->andReturn((object) ['url' => 'https://billing.stripe.test/p_abc']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);
    $stripeClient->billingPortal = (object) ['sessions' => $portalSessionsSpy];

    $service = new StripeBillingService;
    billingTest_injectStripeClient($service, $stripeClient);

    expect($service->createBillingPortalSession($professional, 'https://example.test/return'))
        ->toBe('https://billing.stripe.test/p_abc');
});
