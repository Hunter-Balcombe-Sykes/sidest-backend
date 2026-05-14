<?php

use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Captures what we pass to $stripe->v2->core->accounts->create so we can assert the
// v2 Account creation payload is shaped correctly without hitting the real API.
//
// Under v2 Option A the identity block is minimal: entity_type ('company' for brands,
// 'individual' for affiliates) + country. Richer prefill (company / individual sub-blocks
// with phone, address, etc.) uses a different shape from v1 and is deferred to a future
// phase — see plan §Phase 3.2.

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    // resolveShopCurrency() queries professional_integrations during createConnectAccount.
    // Stub the table so the query returns no rows instead of erroring.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        provider_metadata TEXT,
        shopify_shop_domain TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function makeConnectPrefillProfessional(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    $row = array_merge([
        'id' => $id,
        'handle' => "pro-{$id}",
        'handle_lc' => "pro-{$id}",
        'display_name' => 'Test Affiliate',
        'first_name' => 'Test',
        'last_name' => 'Affiliate',
        'primary_email' => 'test@example.com',
        'phone' => null,
        'professional_type' => 'professional',
        'status' => 'active',
        'country_code' => 'AU',
        'bio' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($row);

    return Professional::find($id);
}

/**
 * Build a StripeClient mock whose v2->core->accounts->create captures the payload it
 * receives. Returns [$client, $captured] where $captured['payload'] is populated after the
 * service call.
 */
function captureV2AccountCreatePayload(): array
{
    $captured = new ArrayObject(['payload' => null]);

    $accounts = Mockery::mock();
    $accounts->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload, $opts = []) use ($captured) {
            $captured['payload'] = $payload;

            return true;
        })
        ->andReturn((object) ['id' => 'acct_fake_test']);

    $core = (object) ['accounts' => $accounts, 'accountLinks' => Mockery::mock()->shouldIgnoreMissing()];
    $v2 = (object) ['core' => $core];

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->v2 = $v2;

    return [$stripe, $captured];
}

function makeService(StripeClient $stripe): StripeConnectService
{
    // The service constructor doesn't accept a client, so swap the internal one via reflection.
    $service = new StripeConnectService(app(CacheLockService::class));
    $ref = new ReflectionClass($service);
    $prop = $ref->getProperty('stripe');
    $prop->setAccessible(true);
    $prop->setValue($service, $stripe);

    return $service;
}

it('brand identity payload includes entity_type, country, and business prefill', function () {
    $brand = makeConnectPrefillProfessional([
        'professional_type' => 'brand',
        'country_code' => 'AU',
        'display_name' => 'Acme Test Co',
    ]);

    [$stripe, $captured] = captureV2AccountCreatePayload();
    makeService($stripe)->createConnectAccount($brand);

    $identity = $captured['payload']['identity'] ?? [];
    expect($identity['entity_type'])->toBe('company');
    expect($identity['country'])->toBe('AU');
    // Phase 5 — name + business_profile prefill from display_name (no Shopify metadata here).
    expect($identity['business']['name'])->toBe('Acme Test Co');
    expect($identity['business']['business_profile']['mcc'])->toBe('5734');
});

it('affiliate identity payload includes individual prefill (given_name + surname + email)', function () {
    $affiliate = makeConnectPrefillProfessional([
        'professional_type' => 'professional',
        'country_code' => 'US',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'primary_email' => 'jane@example.com',
    ]);

    [$stripe, $captured] = captureV2AccountCreatePayload();
    makeService($stripe)->createConnectAccount($affiliate);

    $identity = $captured['payload']['identity'] ?? [];
    expect($identity['entity_type'])->toBe('individual');
    expect($identity['country'])->toBe('US');
    expect($identity['individual']['given_name'])->toBe('Jane');
    expect($identity['individual']['surname'])->toBe('Doe');
    expect($identity['individual']['email'])->toBe('jane@example.com');
});

it('brand prefill uses Shopify shop_name + shop_url when integration metadata is present', function () {
    $brand = makeConnectPrefillProfessional([
        'professional_type' => 'brand',
        'country_code' => 'AU',
        'display_name' => 'Fallback Co',
    ]);

    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brand->id,
        'provider' => 'shopify',
        'provider_metadata' => json_encode([
            'shop_name' => 'Acme Boutique',
            'shop_domain' => 'acme.myshopify.com',
        ]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    [$stripe, $captured] = captureV2AccountCreatePayload();
    makeService($stripe)->createConnectAccount($brand);

    $identity = $captured['payload']['identity'] ?? [];
    // Shop_name from Shopify wins over display_name fallback.
    expect($identity['business']['name'])->toBe('Acme Boutique');
    expect($identity['business']['business_profile']['url'])->toBe('https://acme.myshopify.com');
});

it('affiliate prefill falls back to splitting display_name when first/last not set', function () {
    $affiliate = makeConnectPrefillProfessional([
        'professional_type' => 'professional',
        'country_code' => 'AU',
        'display_name' => 'Alex Influencer',
        'first_name' => null,
        'last_name' => null,
        'primary_email' => 'alex@example.com',
    ]);

    [$stripe, $captured] = captureV2AccountCreatePayload();
    makeService($stripe)->createConnectAccount($affiliate);

    $identity = $captured['payload']['identity'] ?? [];
    expect($identity['individual']['given_name'])->toBe('Alex');
    expect($identity['individual']['surname'])->toBe('Influencer');
});

it('brand v2 payload includes merchant + customer + recipient configurations', function () {
    $brand = makeConnectPrefillProfessional([
        'professional_type' => 'brand',
    ]);

    [$stripe, $captured] = captureV2AccountCreatePayload();
    makeService($stripe)->createConnectAccount($brand);

    $configuration = $captured['payload']['configuration'] ?? [];

    expect($configuration['merchant']['capabilities']['card_payments']['requested'] ?? null)->toBeTrue();
    expect($configuration['customer'])->toBeObject();
    expect($configuration['recipient']['capabilities']['stripe_balance']['stripe_transfers']['requested'] ?? null)->toBeTrue();
});

it('affiliate v2 payload includes recipient configuration only (no merchant or customer)', function () {
    $affiliate = makeConnectPrefillProfessional([
        'professional_type' => 'professional',
    ]);

    [$stripe, $captured] = captureV2AccountCreatePayload();
    makeService($stripe)->createConnectAccount($affiliate);

    $configuration = $captured['payload']['configuration'] ?? [];

    expect($configuration['recipient']['capabilities']['stripe_balance']['stripe_transfers']['requested'] ?? null)->toBeTrue();
    expect($configuration['merchant'] ?? null)->toBeNull();
    expect($configuration['customer'] ?? null)->toBeNull();
});

it('defaults block routes fees and losses to the platform (application) for destination charges', function () {
    $brand = makeConnectPrefillProfessional([
        'professional_type' => 'brand',
    ]);

    [$stripe, $captured] = captureV2AccountCreatePayload();
    makeService($stripe)->createConnectAccount($brand);

    expect($captured['payload']['defaults']['responsibilities']['fees_collector'] ?? null)->toBe('application');
    expect($captured['payload']['defaults']['responsibilities']['losses_collector'] ?? null)->toBe('application');
    expect($captured['payload']['dashboard'] ?? null)->toBe('express');
});
