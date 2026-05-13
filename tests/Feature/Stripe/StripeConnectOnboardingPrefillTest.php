<?php

use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

use function Pest\Laravel\mock;

// Captures what we pass to Stripe accounts->create so we can assert the
// prefill block is shaped correctly without hitting the real API.

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    // createConnectAccount writes stripe_grace_period_ends_at; not in the base helper.
    try {
        DB::connection('pgsql')->statement('ALTER TABLE core.professionals ADD COLUMN stripe_grace_period_ends_at TEXT');
    } catch (\Throwable) {
        // column may already exist from a previous test run within the same connection
    }

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

function makeProfessional(array $overrides = []): Professional
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
        'location_street_address' => null,
        'location_city' => null,
        'location_state' => null,
        'location_postcode' => null,
        'location_country' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    DB::connection('pgsql')->table('core.professionals')->insert($row);

    return Professional::find($id);
}

function captureCreatePayload(): array
{
    // Shared array so the closure can mutate it after the mock invokes.
    $captured = new ArrayObject(['payload' => null]);

    // Avoid Pest's mock() helper here — its proxy was eating the closure args.
    $stripe = Mockery::mock(StripeClient::class);
    $accounts = Mockery::mock();
    $stripe->accounts = $accounts;

    $accounts->shouldReceive('create')
        ->once()
        ->withArgs(function ($payload) use ($captured) {
            $captured['payload'] = $payload;

            return true;
        })
        ->andReturn((object) ['id' => 'acct_fake_test']);

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

it('prefills business_profile and individual blocks from a fully-filled professional', function () {
    // Add the partna_url column so the helper can read it (test schema omits it by default).
    DB::connection('pgsql')->statement('ALTER TABLE core.professionals ADD COLUMN partna_url TEXT');

    $pro = makeProfessional([
        'partna_url' => 'https://test-affiliate.partna.au',
        'phone' => '+61412345678',
        'bio' => 'A bio that should not be sent when URL is present',
        'location_street_address' => '123 Test St',
        'location_city' => 'Sydney',
        'location_state' => 'NSW',
        'location_postcode' => '2000',
        'location_country' => 'AU',
    ]);

    [$stripe, $captured] = captureCreatePayload();
    makeService($stripe)->createConnectAccount($pro);

    expect($captured['payload']['business_profile'] ?? null)->toBe([
        'name' => 'Test Affiliate',
        'url' => 'https://test-affiliate.partna.au',
        'support_email' => 'test@example.com',
    ]);

    expect($captured['payload']['individual'] ?? null)->toBe([
        'first_name' => 'Test',
        'last_name' => 'Affiliate',
        'email' => 'test@example.com',
        'phone' => '+61412345678',
        'address' => [
            'line1' => '123 Test St',
            'city' => 'Sydney',
            'state' => 'NSW',
            'postal_code' => '2000',
            'country' => 'AU',
        ],
    ]);
});

it('sends product_description as a fallback when partna_url is missing', function () {
    DB::connection('pgsql')->statement('ALTER TABLE core.professionals ADD COLUMN partna_url TEXT');

    $pro = makeProfessional([
        'partna_url' => null,
        'bio' => 'I sell handmade soap',
    ]);

    [$stripe, $captured] = captureCreatePayload();
    makeService($stripe)->createConnectAccount($pro);

    $bp = $captured['payload']['business_profile'] ?? [];
    expect($bp)->toHaveKey('product_description', 'I sell handmade soap')
        ->and($bp)->not->toHaveKey('url');
});

it('drops non-E.164 phone numbers and omits address when country is missing', function () {
    DB::connection('pgsql')->statement('ALTER TABLE core.professionals ADD COLUMN partna_url TEXT');

    $pro = makeProfessional([
        'phone' => '0412345678',                     // missing leading '+'
        'location_street_address' => '123 Test St',
        'location_country' => null,                  // address skipped without country
    ]);

    [$stripe, $captured] = captureCreatePayload();
    makeService($stripe)->createConnectAccount($pro);

    $individual = $captured['payload']['individual'] ?? [];
    expect($individual)->not->toHaveKey('phone')
        ->and($individual)->not->toHaveKey('address');
});

it('skips address when location_country is a free-form value Stripe cannot map', function () {
    // Side St had location_country = "Australia" (full name) while country_code = "AU".
    // The unmapped value used to abort the whole onboarding with 422; now it
    // should silently drop the address block and let onboarding proceed.
    DB::connection('pgsql')->statement('ALTER TABLE core.professionals ADD COLUMN partna_url TEXT');

    $pro = makeProfessional([
        'location_street_address' => '37 Hardiman Street',
        'location_city' => 'Kensington',
        'location_state' => 'Victoria',
        'location_postcode' => '3031',
        'location_country' => 'Australia', // free-form, not an ISO code
    ]);

    [$stripe, $captured] = captureCreatePayload();
    makeService($stripe)->createConnectAccount($pro);

    $individual = $captured['payload']['individual'] ?? [];
    expect($individual)->not->toHaveKey('address');
});

it('omits the prefill blocks entirely when the professional has no prefillable fields', function () {
    DB::connection('pgsql')->statement('ALTER TABLE core.professionals ADD COLUMN partna_url TEXT');

    $pro = makeProfessional([
        'display_name' => null,
        'first_name' => null,
        'last_name' => null,
        'primary_email' => null,
        'bio' => null,
        'phone' => null,
        'location_street_address' => null,
        'location_country' => null,
    ]);

    [$stripe, $captured] = captureCreatePayload();
    makeService($stripe)->createConnectAccount($pro);

    expect($captured['payload'])->not->toHaveKey('business_profile')
        ->and($captured['payload'])->not->toHaveKey('individual');
});
