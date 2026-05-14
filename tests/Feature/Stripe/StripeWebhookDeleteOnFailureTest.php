<?php

use App\Services\Professional\SiteProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// STRP-2: verify that StripeWebhookController deletes the webhook_event row when a
// handler throws a transient exception, so Stripe's retry mechanism can re-deliver.

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

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

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        plan_id TEXT NOT NULL,
        provider TEXT NOT NULL DEFAULT \'stripe\',
        stripe_subscription_id TEXT NULL,
        status TEXT NOT NULL DEFAULT \'active\',
        current_period_start TEXT NULL,
        current_period_end TEXT NULL,
        cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
        trial_ends_at TEXT NULL,
        ended_at TEXT NULL,
        provider_payload TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');

    config(['services.stripe.webhook_secret' => 'whsec_billing_test']);
});

it('billing webhook — deletes webhook_event row when subscription handler throws, allowing Stripe to retry', function () {
    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'aff_billing_throw',
        'handle_lc' => 'aff_billing_throw',
        'display_name' => 'Billing Throw',
        'professional_type' => 'affiliate',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $planId = (string) Str::uuid();
    DB::connection('pgsql')->table('billing.plans')->insert([
        'id' => $planId,
        'plan_key' => 'starter',
        'name' => 'Starter',
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $subId = (string) Str::uuid();
    DB::connection('pgsql')->table('billing.subscriptions')->insert([
        'id' => $subId,
        'professional_id' => $proId,
        'plan_id' => $planId,
        'provider' => 'stripe',
        'stripe_subscription_id' => 'sub_billing_throw',
        'status' => 'active',
        'cancel_at_period_end' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // ensureFreeSubscription is called when an affiliate subscription is deleted.
    $mockProvisioner = Mockery::mock(SiteProvisioningService::class);
    $mockProvisioner->shouldReceive('ensureFreeSubscription')
        ->andThrow(new \RuntimeException('Transient provisioning failure'));
    app()->instance(SiteProvisioningService::class, $mockProvisioner);

    $eventPayload = [
        'id' => 'evt_billing_throw_'.Str::random(10),
        'object' => 'event',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'customer.subscription.deleted',
        'data' => [
            'object' => [
                'id' => 'sub_billing_throw',
                'object' => 'subscription',
                'status' => 'canceled',
                'customer' => 'cus_test',
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'cancel_at_period_end' => false,
                'items' => ['data' => []],
                'metadata' => ['sidest_professional_id' => $proId],
            ],
        ],
        'livemode' => false,
    ];

    $body = json_encode($eventPayload);
    $sig = signStripeBody($body, 'whsec_billing_test');

    $this->call('POST', '/api/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertStatus(500);

    // Row deleted so Stripe's retry is not silenced by the dedup guard
    expect(DB::table('billing.webhook_events')->where('stripe_event_id', $eventPayload['id'])->count())->toBe(0);
});
