<?php

use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Verifies that the brand-scoped Checkout Setup flow creates the Customer +
// Session on the BRAND'S OWN Connect account (via the `stripe_account`
// request option), and that the resulting IDs land in the new
// stripe_connect_customer_id / stripe_connect_payment_method_id columns —
// not the platform-scoped columns reserved for SaaS billing.

beforeEach(function () {
    setupProfessionalsTable();
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
        'stripe_connect_customer_id TEXT',
        'stripe_connect_payment_method_id TEXT',
        'stripe_customer_id TEXT',
        'stripe_payment_method_id TEXT',
        'stripe_payment_method_brand TEXT',
        'stripe_payment_method_last4 TEXT',
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

it('creates the brand customer on the brand\'s own Connect account', function () {
    $brand = brandPm_seedBrand();

    $customersMock = Mockery::mock();
    $customersMock->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload, $opts) use ($brand) {
            expect($payload['email'])->toBe('brand@example.com');
            expect($payload['name'])->toBe('Side St');
            expect($opts['stripe_account'])->toBe($brand->stripe_connect_account_id);

            return true;
        })
        ->andReturn((object) ['id' => 'cus_on_brand']);

    $service = brandPm_makeService(['customers' => $customersMock]);
    $id = $service->createBrandConnectCustomer($brand);

    expect($id)->toBe('cus_on_brand');
    expect($brand->fresh()->stripe_connect_customer_id)->toBe('cus_on_brand');
    // Platform-scoped column stays untouched — SaaS-billing customer is separate.
    expect($brand->fresh()->stripe_customer_id)->toBeNull();
});

it('rejects customer create when brand has no Stripe Connect account', function () {
    $brand = brandPm_seedBrand(['stripe_connect_account_id' => null]);

    $customersMock = Mockery::mock();
    $customersMock->shouldNotReceive('create');

    $service = brandPm_makeService(['customers' => $customersMock]);

    expect(fn () => $service->createBrandConnectCustomer($brand))
        ->toThrow(\RuntimeException::class);
});

it('creates a Checkout setup session scoped to the brand\'s Connect account', function () {
    $brand = brandPm_seedBrand(['stripe_connect_customer_id' => 'cus_on_brand']);

    $sessionsMock = Mockery::mock();
    $sessionsMock->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload, $opts) use ($brand) {
            expect($payload['mode'])->toBe('setup');
            expect($payload['customer'])->toBe('cus_on_brand');
            expect($payload['payment_method_types'])->toBe(['card']);
            expect($opts['stripe_account'])->toBe($brand->stripe_connect_account_id);

            return true;
        })
        ->andReturn((object) ['id' => 'cs_test', 'url' => 'https://checkout.stripe.test/cs_test']);

    // Checkout::sessions is accessed via $stripe->checkout->sessions, so we
    // need to mock the chain. Returning a class with a sessions property is
    // the simplest way that matches Stripe's PHP SDK shape.
    $checkoutMock = (object) ['sessions' => $sessionsMock];

    $service = brandPm_makeService(['checkout' => $checkoutMock]);
    $result = $service->createBrandConnectPaymentMethodSetupSession(
        $brand,
        'https://app.example/success',
        'https://app.example/cancel',
    );

    expect($result['session_id'])->toBe('cs_test');
    expect($result['checkout_url'])->toBe('https://checkout.stripe.test/cs_test');
});

it('savePaymentMethod stores IDs in brand-Connect columns + sets default on brand customer', function () {
    $brand = brandPm_seedBrand([
        'stripe_connect_customer_id' => 'cus_on_brand',
    ]);

    $pmsMock = Mockery::mock();
    $pmsMock->shouldReceive('retrieve')
        ->once()
        ->withArgs(function ($id, $_args, $opts) use ($brand) {
            expect($id)->toBe('pm_new');
            expect($opts['stripe_account'])->toBe($brand->stripe_connect_account_id);

            return true;
        })
        ->andReturn((object) ['card' => (object) ['brand' => 'visa', 'last4' => '4242']]);

    $customersMock = Mockery::mock();
    $customersMock->shouldReceive('update')
        ->once()
        ->withArgs(function ($id, $payload, $opts) use ($brand) {
            expect($id)->toBe('cus_on_brand');
            expect($payload['invoice_settings']['default_payment_method'])->toBe('pm_new');
            expect($opts['stripe_account'])->toBe($brand->stripe_connect_account_id);

            return true;
        })
        ->andReturn((object) []);

    $service = brandPm_makeService([
        'paymentMethods' => $pmsMock,
        'customers' => $customersMock,
    ]);

    $service->saveBrandConnectPaymentMethod($brand, 'pm_new');

    $fresh = $brand->fresh();
    expect($fresh->stripe_connect_payment_method_id)->toBe('pm_new');
    expect($fresh->stripe_payment_method_brand)->toBe('visa');
    expect($fresh->stripe_payment_method_last4)->toBe('4242');
    // Platform-scoped PM column untouched.
    expect($fresh->stripe_payment_method_id)->toBeNull();
});

it('brandHasPaymentMethod reads brand-Connect columns only', function () {
    $brand = brandPm_seedBrand([
        // Both platform columns set — should NOT count as having a PM for commissions.
        'stripe_customer_id' => 'cus_platform',
        'stripe_payment_method_id' => 'pm_platform',
    ]);

    $service = brandPm_makeService([]);
    expect($service->brandHasPaymentMethod($brand))->toBeFalse();

    $brand->update([
        'stripe_connect_customer_id' => 'cus_on_brand',
        'stripe_connect_payment_method_id' => 'pm_on_brand',
    ]);
    expect($service->brandHasPaymentMethod($brand->fresh()))->toBeTrue();
});
