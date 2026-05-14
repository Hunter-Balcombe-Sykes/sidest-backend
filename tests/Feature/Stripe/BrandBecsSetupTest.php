<?php

use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Tests for StripeConnectService::createBrandBecsSetupSession.
//
// BECS Direct Debit is an Australian payment method that settles T+2 and carries
// a 7-year dispute window under NPPA rules. Stripe Checkout collects the BSB +
// account number and renders the mandate acceptance UI.
//
// v2 BECS setup uses the same customer_account parameter as card setup, but with
// payment_method_types = ['au_becs_debit']. The session is platform-scoped (no
// stripe_account header). Metadata records requested_method = 'au_becs_debit'.

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

function becs_seedBrand(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => 'BECS Brand',
        'professional_type' => 'brand',
        'status' => 'active',
        'primary_email' => 'brand@example.com',
        'stripe_connect_account_id' => 'acct_becs_test',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return Professional::find($id);
}

function becs_makeService(array $sub): StripeConnectService
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

it('creates a BECS Checkout setup session with au_becs_debit payment method type', function () {
    $brand = becs_seedBrand();

    $sessionsMock = Mockery::mock();
    $sessionsMock->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload) use ($brand) {
            expect($payload['customer_account'])->toBe($brand->stripe_connect_account_id);
            expect($payload['mode'])->toBe('setup');
            expect($payload['payment_method_types'])->toBe(['au_becs_debit']);
            expect($payload['metadata']['requested_method'])->toBe('au_becs_debit');
            expect($payload['metadata']['purpose'])->toBe('brand_commission_payment_method');
            expect($payload['metadata']['sidest_professional_id'])->toBe($brand->id);

            return true;
        })
        ->andReturn((object) ['id' => 'cs_becs_test', 'url' => 'https://checkout.stripe.test/cs_becs_test']);

    $checkoutMock = (object) ['sessions' => $sessionsMock];

    $service = becs_makeService(['checkout' => $checkoutMock]);
    $result = $service->createBrandBecsSetupSession(
        $brand,
        'https://app.example/becs-success',
        'https://app.example/becs-cancel',
    );

    expect($result['session_id'])->toBe('cs_becs_test');
    expect($result['checkout_url'])->toBe('https://checkout.stripe.test/cs_becs_test');
});

it('distinguishes BECS setup from card setup by payment method type', function () {
    $brand = becs_seedBrand();

    $sessionsMock = Mockery::mock();
    $sessionsMock->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload) {
            // Card setup would use ['card'], BECS uses ['au_becs_debit']
            expect($payload['payment_method_types'])->toBe(['au_becs_debit']);

            return true;
        })
        ->andReturn((object) ['id' => 'cs_becs_2', 'url' => 'https://checkout.stripe.test/cs_becs_2']);

    $checkoutMock = (object) ['sessions' => $sessionsMock];

    $service = becs_makeService(['checkout' => $checkoutMock]);
    $service->createBrandBecsSetupSession(
        $brand,
        'https://app.example/success',
        'https://app.example/cancel',
    );
});

it('rejects BECS setup when brand has no Stripe account', function () {
    $brand = becs_seedBrand(['stripe_connect_account_id' => null]);

    $service = becs_makeService([]);

    expect(fn () => $service->createBrandBecsSetupSession(
        $brand,
        'https://app.example/success',
        'https://app.example/cancel',
    ))->toThrow(\RuntimeException::class, 'Stripe Connect');
});

it('rejects BECS setup when brand Stripe status is not active or restricted', function () {
    $brand = becs_seedBrand(['stripe_connect_status' => 'onboarding']);

    $service = becs_makeService([]);

    expect(fn () => $service->createBrandBecsSetupSession(
        $brand,
        'https://app.example/success',
        'https://app.example/cancel',
    ))->toThrow(\RuntimeException::class, 'not active');
});

it('syncBrandPaymentMethodFromCheckoutSession sets payout_method=becs for au_becs_debit PM', function () {
    $brand = becs_seedBrand();

    $paymentMethod = (object) [
        'id' => 'pm_becs',
        'type' => 'au_becs_debit',
        'au_becs_debit' => (object) [
            'bsb_number' => '082902',
            'last4' => '5678',
            'fingerprint' => 'fp_becs_1',
        ],
    ];

    $setupIntent = (object) [
        'status' => 'succeeded',
        'payment_method' => $paymentMethod,
    ];

    $sessionObj = (object) [
        'id' => 'cs_becs_sync',
        'mode' => 'setup',
        'status' => 'complete',
        'metadata' => (object) ['sidest_professional_id' => $brand->id],
        'setup_intent' => $setupIntent,
    ];

    $sessionsMock = Mockery::mock();
    $sessionsMock->shouldReceive('retrieve')
        ->once()
        ->with('cs_becs_sync', Mockery::any())
        ->andReturn($sessionObj);

    $checkoutMock = (object) ['sessions' => $sessionsMock];

    $service = becs_makeService(['checkout' => $checkoutMock]);
    $result = $service->syncBrandPaymentMethodFromCheckoutSession($brand, 'cs_becs_sync');

    expect($result['payment_method_id'])->toBe('pm_becs');
    expect($result['payout_method'])->toBe('becs');

    $fresh = $brand->fresh();
    expect($fresh->stripe_payment_method_id)->toBe('pm_becs');
    expect($fresh->payout_method)->toBe('becs');
    // BECS stores last4 from the account number
    expect($fresh->stripe_payment_method_last4)->toBe('5678');
});
