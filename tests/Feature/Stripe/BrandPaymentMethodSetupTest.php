<?php

use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Verifies the v2 brand payment-method setup flow:
//  - Checkout setup sessions use customer_account (the brand's v2 Account ID), not
//    a separate Customer object and not a stripe_account header.
//  - syncBrandPaymentMethodFromCheckoutSession retrieves the completed setup intent
//    and persists the PM ID + display fields to stripe_payment_method_id (the single
//    canonical column — there is no separate stripe_connect_payment_method_id in v2).
//  - brandHasPaymentMethod reads stripe_payment_method_id.

beforeEach(function () {
    setupProfessionalsTable();
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
        'stripe_payment_method_id TEXT',
        'stripe_payment_method_brand TEXT',
        'stripe_payment_method_last4 TEXT',
        'payout_method TEXT',
    ] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }
});

function brandPm_seedBrand(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => 'Side St',
        'professional_type' => 'brand',
        'status' => 'active',
        'primary_email' => 'brand@example.com',
        'stripe_connect_account_id' => 'acct_brand_test',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return Professional::find($id);
}

function brandPm_makeService(array $sub): StripeConnectService
{
    $stripe = Mockery::mock(StripeClient::class);
    foreach ($sub as $name => $impl) {
        $stripe->shouldReceive('getService')->with($name)->andReturn($impl);
    }
    $stripe->shouldReceive('getService')->andReturn(Mockery::mock()->shouldIgnoreMissing());

    $service = new StripeConnectService(app(CacheLockService::class));
    $ref = new \ReflectionClass($service);
    $prop = $ref->getProperty('stripe');
    $prop->setAccessible(true);
    $prop->setValue($service, $stripe);

    return $service;
}

it('creates a Checkout setup session with customer_account (not stripe_account header)', function () {
    $brand = brandPm_seedBrand();

    $sessionsMock = Mockery::mock();
    $sessionsMock->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload) use ($brand) {
            // v2: customer_account ties the SetupIntent to the brand's Account directly.
            expect($payload['customer_account'])->toBe($brand->stripe_connect_account_id);
            expect($payload['mode'])->toBe('setup');
            expect($payload['payment_method_types'])->toBe(['card']);

            return true;
        })
        ->andReturn((object) ['id' => 'cs_test', 'url' => 'https://checkout.stripe.test/cs_test']);

    $checkoutMock = (object) ['sessions' => $sessionsMock];

    $service = brandPm_makeService(['checkout' => $checkoutMock]);
    $result = $service->createBrandPaymentMethodSetupSession(
        $brand,
        'https://app.example/success',
        'https://app.example/cancel',
    );

    expect($result['session_id'])->toBe('cs_test');
    expect($result['checkout_url'])->toBe('https://checkout.stripe.test/cs_test');
});

it('syncBrandPaymentMethodFromCheckoutSession persists PM ID to stripe_payment_method_id', function () {
    $brand = brandPm_seedBrand();

    $paymentMethod = (object) [
        'id' => 'pm_new',
        'type' => 'card',
        'card' => (object) ['brand' => 'visa', 'last4' => '4242'],
    ];

    $setupIntent = (object) [
        'status' => 'succeeded',
        'payment_method' => $paymentMethod,
    ];

    $sessionObj = (object) [
        'id' => 'cs_test',
        'mode' => 'setup',
        'status' => 'complete',
        'metadata' => (object) ['sidest_professional_id' => $brand->id],
        'setup_intent' => $setupIntent,
    ];

    $sessionsMock = Mockery::mock();
    $sessionsMock->shouldReceive('retrieve')
        ->once()
        ->with('cs_test', Mockery::any())
        ->andReturn($sessionObj);

    $checkoutMock = (object) ['sessions' => $sessionsMock];

    $service = brandPm_makeService(['checkout' => $checkoutMock]);
    $result = $service->syncBrandPaymentMethodFromCheckoutSession($brand, 'cs_test');

    expect($result['payment_method_id'])->toBe('pm_new');
    expect($result['payout_method'])->toBe('card');

    $fresh = $brand->fresh();
    expect($fresh->stripe_payment_method_id)->toBe('pm_new');
    expect($fresh->stripe_payment_method_brand)->toBe('visa');
    expect($fresh->stripe_payment_method_last4)->toBe('4242');
});

it('brandHasPaymentMethod reads stripe_payment_method_id', function () {
    $brand = brandPm_seedBrand();

    $service = brandPm_makeService([]);
    expect($service->brandHasPaymentMethod($brand))->toBeFalse();

    $brand->update(['stripe_payment_method_id' => 'pm_on_brand']);
    expect($service->brandHasPaymentMethod($brand->fresh()))->toBeTrue();
});

it('brand with null stripe_connect_account_id cannot sync payment method', function () {
    $brand = brandPm_seedBrand(['stripe_connect_account_id' => null]);

    $service = brandPm_makeService([]);

    expect(fn () => $service->syncBrandPaymentMethodFromCheckoutSession($brand, 'cs_test'))
        ->toThrow(\RuntimeException::class, 'no Stripe Connect account');
});
