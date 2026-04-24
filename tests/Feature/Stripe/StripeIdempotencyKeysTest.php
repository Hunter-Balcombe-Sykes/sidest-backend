<?php

use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Verifies that the four Stripe API write calls that were missing idempotency
// keys now pass deterministic keys derived from local resource IDs.
//
// StripeClient uses __get() for lazy service accessors; mocks intercept __get.

beforeEach(function () {
    // Real StripeClient constructor validates the key is a non-null string.
    // We swap $this->stripe via reflection right after, but the constructor
    // still runs first, so we provide a placeholder key.
    config(['services.stripe.secret_key' => 'sk_test_placeholder']);

    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT DEFAULT \'not_connected\'',
        'stripe_grace_period_ends_at TEXT',
        'stripe_customer_id TEXT',
        'stripe_payment_method_id TEXT',
        'stripe_manual_balance_cents INTEGER DEFAULT 0',
        'stripe_manual_balance_currency TEXT',
        'country_code TEXT',
        'primary_email TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }
});

function idempotencyTest_makeProfessional(?string $id = null): Professional
{
    $id ??= (string) Str::uuid();

    return Professional::create([
        'id' => $id,
        'handle' => "h-{$id}",
        'handle_lc' => "h-{$id}",
        'display_name' => "Pro {$id}",
        'primary_email' => "{$id}@example.test",
        'professional_type' => 'affiliate',
        'status' => 'active',
    ]);
}

// ── Task 2: StripeBillingService::ensureStripeCustomer ──────────────────────

it('ensureStripeCustomer passes deterministic idempotency_key to Stripe', function () {
    $professional = idempotencyTest_makeProfessional();

    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($professional) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe("customer_{$professional->id}");
            expect($params['email'])->toBe($professional->primary_email);

            return true;
        })
        ->andReturn((object) ['id' => 'cus_fake_abc']);

    $stripeClient = Mockery::mock(StripeClient::class);
    // StripeClient::__get delegates to getService() — mock the actual method.
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);

    $service = new StripeBillingService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $customerId = $service->ensureStripeCustomer($professional);

    expect($customerId)->toBe('cus_fake_abc');
    expect($professional->fresh()->stripe_customer_id)->toBe('cus_fake_abc');
});

it('ensureStripeCustomer skips Stripe when customer already exists', function () {
    $professional = idempotencyTest_makeProfessional();
    $professional->update(['stripe_customer_id' => 'cus_existing']);

    $customersSpy = Mockery::mock();
    $customersSpy->shouldNotReceive('create');

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy)->zeroOrMoreTimes();

    $service = new StripeBillingService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    expect($service->ensureStripeCustomer($professional))->toBe('cus_existing');
});

// ── Task 3: StripeConnectService::createConnectAccount ──────────────────────

it('createConnectAccount passes deterministic idempotency_key to Stripe', function () {
    $professional = idempotencyTest_makeProfessional();
    $professional->update(['country_code' => 'AU']);

    $accountsSpy = Mockery::mock();
    $accountsSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($professional) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe("acct_{$professional->id}");
            expect($params['type'])->toBe('express');
            expect($params['country'])->toBe('AU');

            return true;
        })
        ->andReturn((object) ['id' => 'acct_fake_abc']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('accounts')->andReturn($accountsSpy);

    $service = new \App\Services\Stripe\StripeConnectService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $accountId = $service->createConnectAccount($professional);

    expect($accountId)->toBe('acct_fake_abc');
    expect($professional->fresh()->stripe_connect_account_id)->toBe('acct_fake_abc');
});

// ── Task 4: StripeConnectService::createCustomer (brand flow) ───────────────

it('createCustomer (Connect/brand) passes deterministic idempotency_key to Stripe', function () {
    $brand = idempotencyTest_makeProfessional();
    $brand->update(['professional_type' => 'brand']);

    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($brand) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe("customer_{$brand->id}");

            return true;
        })
        ->andReturn((object) ['id' => 'cus_brand_abc']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);

    $service = new \App\Services\Stripe\StripeConnectService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $customerId = $service->createCustomer($brand);

    expect($customerId)->toBe('cus_brand_abc');
    expect($brand->fresh()->stripe_customer_id)->toBe('cus_brand_abc');
});

// ── Task 5: CommissionPayoutService refund idempotency key (source assertion) ─

it('CommissionPayoutService refund call includes payout+PI idempotency_key', function () {
    $source = file_get_contents(base_path('app/Services/Stripe/CommissionPayoutService.php'));

    // The refund key is tied to both payout ID and PI ID. Including the PI
    // matters: the failure path clears stripe_payment_intent_id on a successful
    // refund so an admin retry creates a fresh PI with a distinct key.
    expect($source)->toMatch(
        '/refunds->create\s*\(\s*\[\s*[^\]]*payment_intent[^\]]*\]\s*,\s*\[\s*[\'"]idempotency_key[\'"]\s*=>\s*[\'"]rf_\{\$payout->id\}_\{\$payout->stripe_payment_intent_id\}[\'"]\s*\]\s*\)/'
    );
});
