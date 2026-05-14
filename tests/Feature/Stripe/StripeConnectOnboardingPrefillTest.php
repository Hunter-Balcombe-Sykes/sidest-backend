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

it('brand identity payload sets entity_type=company and country', function () {
    $brand = makeConnectPrefillProfessional([
        'professional_type' => 'brand',
        'country_code' => 'AU',
    ]);

    [$stripe, $captured] = captureV2AccountCreatePayload();
    makeService($stripe)->createConnectAccount($brand);

    expect($captured['payload']['identity'] ?? null)->toBe([
        'entity_type' => 'company',
        'country' => 'AU',
    ]);
});

it('affiliate identity payload sets entity_type=individual and country', function () {
    $affiliate = makeConnectPrefillProfessional([
        'professional_type' => 'professional',
        'country_code' => 'US',
    ]);

    [$stripe, $captured] = captureV2AccountCreatePayload();
    makeService($stripe)->createConnectAccount($affiliate);

    expect($captured['payload']['identity'] ?? null)->toBe([
        'entity_type' => 'individual',
        'country' => 'US',
    ]);
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
