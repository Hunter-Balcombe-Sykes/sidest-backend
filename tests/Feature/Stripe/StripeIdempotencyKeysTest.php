<?php

use App\Models\Billing\Plan;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\StripeClient;

// Verifies idempotency-key formats for v2 Stripe API writes.
//
// v2 key formats:
//   - Billing customer:  customer_{professional_id}
//   - Billing checkout session: checkout_{professional_id}_{plan_id}_{hour_bucket}
//   - Brand v2 Account:  acct_brand_{professional_id}
//   - Affiliate v2 Account: acct_affiliate_{professional_id}
//   - CommissionPayoutService PI: pi_{payout_id}[_r{retry}]  (covered in CommissionPayoutServiceTest)
//   - CommissionPayoutRefundService refund: rf_{payout_id}_{order_id}_{hash}  (covered in CommissionPayoutRefundServiceTest)

beforeEach(function () {
    config(['services.stripe.secret_key' => 'sk_test_placeholder']);

    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_connect_account_id TEXT',
        "stripe_connect_status TEXT DEFAULT 'not_connected'",
        'stripe_customer_id TEXT',
        'stripe_payment_method_id TEXT',
        'country_code TEXT',
        'primary_email TEXT',
        'first_name TEXT',
        'last_name TEXT',
        'business_name TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.plans (
        id TEXT PRIMARY KEY,
        plan_key TEXT NOT NULL,
        name TEXT NULL,
        stripe_price_id TEXT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NULL,
        price_cents INTEGER NULL,
        currency_code TEXT NULL,
        billing_interval TEXT NULL,
        entitlements TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        provider TEXT NULL,
        external_account_id TEXT NULL,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        storefront_token TEXT NULL,
        expires_at TEXT NULL,
        catalog_latest_time TEXT NULL,
        last_catalog_sync_at TEXT NULL,
        last_catalog_sync_error TEXT NULL,
        provider_metadata TEXT NULL,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
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

// ── StripeBillingService::ensureStripeCustomer ─────────────────────────────

it('ensureStripeCustomer passes deterministic idempotency_key to Stripe', function () {
    $professional = idempotencyTest_makeProfessional();

    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($professional) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe("customer_{$professional->id}");
            expect($params['email'])->toBe($professional->primary_email);
            // Metadata threads the professional through for webhook reconciliation.
            expect($params['metadata']['sidest_professional_id'])->toBe($professional->id);

            return true;
        })
        ->andReturn((object) ['id' => 'cus_fake_abc']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);

    $service = new StripeBillingService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $customerId = $service->ensureStripeCustomer($professional);

    expect($customerId)->toBe('cus_fake_abc');
    // Under v2 Option A, stripe_customer_id is no longer cached on core.professionals (column
    // dropped). The deterministic idempotency key ensures Stripe returns the same customer
    // within the 24h dedup window; longer-term tracking lives on billing.subscriptions.
});

it('ensureStripeCustomer relies on Stripe idempotency for dedup (no professional-side cache under v2)', function () {
    $professional = idempotencyTest_makeProfessional();

    // Under v2, calling ensureStripeCustomer twice in a row hits Stripe twice with the
    // same idempotency_key; Stripe returns the original Customer on the second call.
    // Test verifies the call-twice-with-same-key pattern via the spy.
    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')
        ->twice()
        ->withArgs(function (array $params, array $opts = []) use ($professional) {
            expect($opts['idempotency_key'])->toBe("customer_{$professional->id}");

            return true;
        })
        ->andReturn((object) ['id' => 'cus_dedup_via_stripe']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);

    $service = new StripeBillingService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    expect($service->ensureStripeCustomer($professional))->toBe('cus_dedup_via_stripe');
    expect($service->ensureStripeCustomer($professional))->toBe('cus_dedup_via_stripe');
});

// ── StripeBillingService::createCheckoutSession ────────────────────────────

it('createCheckoutSession passes hour-bucketed idempotency_key to Stripe', function () {
    $professional = idempotencyTest_makeProfessional();

    $planId = (string) Str::uuid();
    DB::connection('pgsql')->table('billing.plans')->insert([
        'id' => $planId,
        'plan_key' => 'growth',
        'name' => 'Growth',
        'stripe_price_id' => 'price_growth_test',
        'is_active' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    $plan = Plan::find($planId);

    // Fix wall-clock so the test asserts a known hour bucket regardless of when CI runs.
    $frozen = \Carbon\Carbon::parse('2026-05-17 14:30:00');
    \Illuminate\Support\Carbon::setTestNow($frozen);
    $expectedBucket = (int) floor($frozen->timestamp / 3600);
    $expectedKey = "checkout_{$professional->id}_{$plan->id}_{$expectedBucket}";

    $customersSpy = Mockery::mock();
    $customersSpy->shouldReceive('create')->andReturn((object) ['id' => 'cus_for_checkout']);

    $sessionsSpy = Mockery::mock();
    $sessionsSpy->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($expectedKey, $professional, $plan) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe($expectedKey);

            expect($params['mode'])->toBe('subscription');
            expect($params['line_items'][0]['price'])->toBe($plan->stripe_price_id);
            expect($params['metadata']['sidest_professional_id'])->toBe($professional->id);
            expect($params['metadata']['sidest_plan_id'])->toBe($plan->id);

            return true;
        })
        ->andReturn((object) ['id' => 'cs_test_123', 'url' => 'https://stripe.test/cs_123']);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->shouldReceive('getService')->with('customers')->andReturn($customersSpy);
    $stripeClient->checkout = (object) ['sessions' => $sessionsSpy];

    $service = new StripeBillingService;
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $result = $service->createCheckoutSession(
        $professional,
        $plan,
        'https://example.test/success',
        'https://example.test/cancel',
    );

    expect($result['session_id'])->toBe('cs_test_123');
    expect($result['checkout_url'])->toBe('https://stripe.test/cs_123');

    \Illuminate\Support\Carbon::setTestNow();
});

// ── StripeConnectService::createConnectAccount (brand → v2) ─────────────────

it('brand createConnectAccount passes idempotency_key acct_brand_{id} to Stripe v2', function () {
    $brand = idempotencyTest_makeProfessional();
    $brand->update([
        'professional_type' => 'brand',
        'country_code' => 'AU',
        'business_name' => 'Test Brand',
        'primary_email' => 'brand@example.test',
    ]);

    // v2 accounts are created via $stripe->v2->core->accounts->create(), not
    // $stripe->accounts->create(). We mock the v2 chain.
    $v2AccountsMock = Mockery::mock();
    $v2AccountsMock->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($brand) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe("acct_brand_{$brand->id}");

            // v2 payload shape — no 'type' key, uses 'configuration' instead
            expect($params)->toHaveKey('configuration');
            expect($params['configuration'])->toHaveKey('merchant');
            expect($params['configuration'])->toHaveKey('recipient');
            expect($params)->not->toHaveKey('type');

            return true;
        })
        ->andReturn((object) ['id' => 'acct_v2_brand']);

    $v2CoreMock = (object) ['accounts' => $v2AccountsMock];
    $v2Mock = (object) ['core' => $v2CoreMock];

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->v2 = $v2Mock;

    $service = app(StripeConnectService::class);
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $accountId = $service->createConnectAccount($brand);

    expect($accountId)->toBe('acct_v2_brand');
});

// ── StripeConnectService::createConnectAccount (affiliate → v2) ─────────────

it('affiliate createConnectAccount passes idempotency_key acct_affiliate_{id} to Stripe v2', function () {
    $affiliate = idempotencyTest_makeProfessional();
    $affiliate->update([
        'professional_type' => 'professional',
        'country_code' => 'AU',
        'first_name' => 'Alex',
        'last_name' => 'Aff',
    ]);

    $v2AccountsMock = Mockery::mock();
    $v2AccountsMock->shouldReceive('create')
        ->once()
        ->withArgs(function (array $params, array $opts = []) use ($affiliate) {
            expect($opts)->toHaveKey('idempotency_key');
            expect($opts['idempotency_key'])->toBe("acct_affiliate_{$affiliate->id}");
            expect($params['configuration'])->toHaveKey('recipient');
            expect($params['configuration'])->not->toHaveKey('merchant');

            return true;
        })
        ->andReturn((object) ['id' => 'acct_v2_aff']);

    $v2CoreMock = (object) ['accounts' => $v2AccountsMock];
    $v2Mock = (object) ['core' => $v2CoreMock];

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->v2 = $v2Mock;

    $service = app(StripeConnectService::class);
    $reflProp = (new ReflectionClass($service))->getProperty('stripe');
    $reflProp->setAccessible(true);
    $reflProp->setValue($service, $stripeClient);

    $accountId = $service->createConnectAccount($affiliate);

    expect($accountId)->toBe('acct_v2_aff');
});

// CommissionPayoutService PI idempotency key (pi_{payout_id}[_r{retry}]) and
// CommissionPayoutRefundService refund idempotency key
// (rf_{payout_id}_{order_id}_{hash}) are covered behaviorally in
// CommissionPayoutServiceTest and CommissionPayoutRefundServiceTest.
