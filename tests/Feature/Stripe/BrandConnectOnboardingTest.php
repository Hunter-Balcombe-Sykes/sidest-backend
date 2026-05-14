<?php

use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Verifies that StripeConnectService::createConnectAccount emits v2 Account shapes
// per professional type:
//   - Brands: entity_type=company, merchant + customer + recipient configurations
//   - Affiliates (professional + influencer): entity_type=individual, recipient only
//
// v2 API uses $stripe->v2->core->accounts->create() with 'configuration' arrays
// instead of the v1 $stripe->accounts->create() with 'type' + 'capabilities'.
// Responsibilities ($fees_collector, $losses_collector) are application-collected;
// dashboard=express.

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
    $v2AccountsMock = Mockery::mock();
    $expectAccountsCreate($v2AccountsMock);

    $v2CoreMock = (object) ['accounts' => $v2AccountsMock];
    $v2Mock = (object) ['core' => $v2CoreMock];

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->v2 = $v2Mock;

    $service = new StripeConnectService(app(CacheLockService::class));

    $ref = new \ReflectionClass($service);
    $prop = $ref->getProperty('stripe');
    $prop->setAccessible(true);
    $prop->setValue($service, $stripe);

    return $service;
}

it('brand creates v2 Account with entity_type=company and three configurations', function () {
    $brand = brandConnect_seedProfessional('brand', ['display_name' => 'Side St']);

    $service = brandConnect_makeService(function ($v2Accounts) {
        $v2Accounts->shouldReceive('create')
            ->once()
            ->withArgs(function ($payload, $opts) {
                // v2: no 'type' key, uses 'configuration' instead
                expect($payload)->not->toHaveKey('type');
                expect($payload)->toHaveKey('configuration');
                // Three configurations for brands
                expect($payload['configuration'])->toHaveKey('merchant');
                expect($payload['configuration'])->toHaveKey('customer');
                expect($payload['configuration'])->toHaveKey('recipient');
                // merchant.card_payments requested
                expect($payload['configuration']['merchant']['capabilities']['card_payments']['requested'])->toBeTrue();
                // recipient.stripe_balance.stripe_transfers requested
                expect($payload['configuration']['recipient']['capabilities']['stripe_balance']['stripe_transfers']['requested'])->toBeTrue();
                // entity_type (not business_type) for identity
                expect($payload['identity']['entity_type'])->toBe('company');
                // Responsibilities
                expect($payload['defaults']['responsibilities']['fees_collector'])->toBe('application');
                expect($payload['defaults']['responsibilities']['losses_collector'])->toBe('application');
                // Dashboard
                expect($payload['dashboard'])->toBe('express');
                // Idempotency key
                expect($opts['idempotency_key'])->toContain('acct_brand_');

                return true;
            })
            ->andReturn((object) ['id' => 'acct_brand_test']);
    });

    $id = $service->createConnectAccount($brand);

    expect($id)->toBe('acct_brand_test');
    expect($brand->fresh()->stripe_connect_account_id)->toBe('acct_brand_test');
    expect($brand->fresh()->stripe_connect_status)->toBe('onboarding');
});

it('affiliate creates v2 Account with entity_type=individual and recipient-only', function () {
    $affiliate = brandConnect_seedProfessional('professional', [
        'first_name' => 'Alex',
        'last_name' => 'Aff',
    ]);

    $service = brandConnect_makeService(function ($v2Accounts) {
        $v2Accounts->shouldReceive('create')
            ->once()
            ->withArgs(function ($payload, $opts) {
                expect($payload['identity']['entity_type'])->toBe('individual');
                expect($payload['configuration'])->toHaveKey('recipient');
                // No merchant or customer for affiliates
                expect($payload['configuration'])->not->toHaveKey('merchant');
                expect($payload['configuration'])->not->toHaveKey('customer');

                return true;
            })
            ->andReturn((object) ['id' => 'acct_aff_test']);
    });

    $id = $service->createConnectAccount($affiliate);

    expect($id)->toBe('acct_aff_test');
});

it('influencer (non-brand) gets the individual + recipient-only shape too', function () {
    $influencer = brandConnect_seedProfessional('influencer');

    $service = brandConnect_makeService(function ($v2Accounts) {
        $v2Accounts->shouldReceive('create')
            ->once()
            ->withArgs(function ($payload, $opts) {
                expect($payload['identity']['entity_type'])->toBe('individual');
                expect($payload['configuration'])->not->toHaveKey('merchant');

                return true;
            })
            ->andReturn((object) ['id' => 'acct_inf_test']);
    });

    $service->createConnectAccount($influencer);
});
