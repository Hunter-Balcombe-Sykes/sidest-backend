<?php

use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Verifies that StripeConnectService::createConnectAccount emits the right
// Express-account shape per professional type — affiliates get
// business_type=individual with transfers only; brands get business_type=
// company with BOTH transfers AND card_payments (Stripe forces both when an
// account both accepts charges AND forwards funds).

beforeEach(function () {
    setupProfessionalsTable();
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
        'stripe_grace_period_ends_at TEXT',
        'stripe_manual_balance_currency TEXT',
        'partna_url TEXT',
    ] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    // resolveShopCurrency() consults this table on the create path.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        provider_metadata TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function brandConnect_seedProfessional(string $type, array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "p-{$id}",
        'handle_lc' => "p-{$id}",
        'display_name' => 'Test Pro',
        'professional_type' => $type,
        'status' => 'active',
        'country_code' => 'AU',
        'primary_email' => 'test@example.com',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return Professional::find($id);
}

function brandConnect_makeService(\Closure $expectAccountsCreate): StripeConnectService
{
    $accountsMock = Mockery::mock();
    $expectAccountsCreate($accountsMock);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->shouldReceive('getService')->with('accounts')->andReturn($accountsMock);
    $stripe->shouldReceive('getService')->andReturn(Mockery::mock()->shouldIgnoreMissing());

    // CacheLockService isn't exercised on the create path; pass a real one.
    $service = new StripeConnectService(app(CacheLockService::class));

    // Override the private $stripe field via reflection so the mock is used.
    $ref = new \ReflectionClass($service);
    $prop = $ref->getProperty('stripe');
    $prop->setAccessible(true);
    $prop->setValue($service, $stripe);

    return $service;
}

it('brand creates Connect Express account with business_type=company and both capabilities', function () {
    $brand = brandConnect_seedProfessional('brand', ['display_name' => 'Side St']);

    $service = brandConnect_makeService(function ($accountsMock) {
        $accountsMock->shouldReceive('create')
            ->once()
            ->withArgs(function ($payload, $opts) {
                expect($payload['type'])->toBe('express');
                expect($payload['country'])->toBe('AU');
                expect($payload['business_type'])->toBe('company');
                expect($payload['capabilities'])->toHaveKey('transfers');
                expect($payload['capabilities'])->toHaveKey('card_payments');
                expect($payload['capabilities']['transfers']['requested'])->toBeTrue();
                expect($payload['capabilities']['card_payments']['requested'])->toBeTrue();
                // company block (not individual) for brands
                expect($payload)->toHaveKey('company');
                expect($payload)->not->toHaveKey('individual');
                expect($payload['company']['name'])->toBe('Side St');

                return true;
            })
            ->andReturn((object) ['id' => 'acct_brand_test']);
    });

    $id = $service->createConnectAccount($brand);

    expect($id)->toBe('acct_brand_test');
    expect($brand->fresh()->stripe_connect_account_id)->toBe('acct_brand_test');
    expect($brand->fresh()->stripe_connect_status)->toBe('onboarding');
});

it('affiliate creates Connect Express account with business_type=individual and transfers-only', function () {
    $affiliate = brandConnect_seedProfessional('professional', [
        'first_name' => 'Alex',
        'last_name' => 'Aff',
    ]);

    $service = brandConnect_makeService(function ($accountsMock) {
        $accountsMock->shouldReceive('create')
            ->once()
            ->withArgs(function ($payload, $opts) {
                expect($payload['business_type'])->toBe('individual');
                expect($payload['capabilities'])->toHaveKey('transfers');
                // card_payments must NOT be requested for affiliates — they only receive,
                // they don't charge customers themselves.
                expect($payload['capabilities'])->not->toHaveKey('card_payments');
                // individual block (not company) for affiliates
                expect($payload)->toHaveKey('individual');
                expect($payload)->not->toHaveKey('company');

                return true;
            })
            ->andReturn((object) ['id' => 'acct_aff_test']);
    });

    $id = $service->createConnectAccount($affiliate);

    expect($id)->toBe('acct_aff_test');
});

it('influencer (non-brand) gets the individual + transfers-only shape too', function () {
    $influencer = brandConnect_seedProfessional('influencer');

    $service = brandConnect_makeService(function ($accountsMock) {
        $accountsMock->shouldReceive('create')
            ->once()
            ->withArgs(function ($payload, $opts) {
                expect($payload['business_type'])->toBe('individual');
                expect($payload['capabilities'])->not->toHaveKey('card_payments');

                return true;
            })
            ->andReturn((object) ['id' => 'acct_inf_test']);
    });

    $service->createConnectAccount($influencer);
});
