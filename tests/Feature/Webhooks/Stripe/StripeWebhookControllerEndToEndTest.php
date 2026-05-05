<?php

use App\Models\Billing\Subscription;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.plans (
        id TEXT PRIMARY KEY,
        plan_key TEXT NOT NULL,
        name TEXT NULL,
        description TEXT NULL,
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
        stripe_customer_id TEXT NULL,
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

    Config::set('services.stripe.webhook_secret', 'whsec_test_billing_secret');
});

function realStripeSubscriptionUpdatedEvent(string $subscriptionId, string $customerId, string $priceId): array
{
    return [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'id' => $subscriptionId,
                'object' => 'subscription',
                'customer' => $customerId,
                'status' => 'active',
                'cancel_at_period_end' => false,
                'current_period_start' => 1714000000,
                'current_period_end' => 1716678400,
                'items' => [
                    'object' => 'list',
                    'data' => [[
                        'id' => 'si_'.Str::random(14),
                        'price' => ['id' => $priceId, 'object' => 'price'],
                    ]],
                ],
                'metadata' => [],
            ],
        ],
        'livemode' => false,
        'pending_webhooks' => 1,
        'request' => ['id' => null, 'idempotency_key' => null],
    ];
}

it('stripe billing — rejects 400 when Stripe-Signature header is missing', function () {
    $this->postJson('/api/webhooks/stripe', ['type' => 'customer.subscription.updated'])
        ->assertStatus(400)
        ->assertJson(['error' => 'Missing signature']);
});

it('stripe billing — accepts a real-shape customer.subscription.updated and persists status change', function () {
    $proId = (string) Str::uuid();
    $planId = (string) Str::uuid();
    $localSubId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        'id' => $proId, 'handle' => 'pro1', 'professional_type' => 'professional', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('billing.plans')->insert([
        'id' => $planId, 'plan_key' => 'pro', 'stripe_price_id' => 'price_pro_monthly', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('billing.subscriptions')->insert([
        'id' => $localSubId, 'professional_id' => $proId, 'plan_id' => $planId,
        'provider' => 'stripe', 'stripe_customer_id' => 'cus_real', 'stripe_subscription_id' => 'sub_real',
        'status' => 'past_due', 'current_period_start' => '2024-01-01', 'current_period_end' => '2024-02-01',
        'cancel_at_period_end' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $event = realStripeSubscriptionUpdatedEvent('sub_real', 'cus_real', 'price_pro_monthly');
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_test_billing_secret');

    $this->call('POST', '/api/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $updated = Subscription::find($localSubId);
    expect($updated->status)->toBe('active');
});

it('stripe billing — rejects 400 when event payload is structurally incomplete (missing data)', function () {
    // Valid HMAC, valid JSON, but missing 'data' — constructEvent() succeeds but our structural
    // check should reject it before storage.
    $body = json_encode([
        'id' => 'evt_'.Str::random(20),
        'type' => 'customer.subscription.updated',
    ]);
    $sig = signStripeBody($body, 'whsec_test_billing_secret');

    $this->call('POST', '/api/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)
        ->assertStatus(400)
        ->assertJson(['error' => 'Invalid payload structure']);
});

it('stripe billing — same event_id arriving twice processes only once', function () {
    $event = realStripeSubscriptionUpdatedEvent('sub_unknown', 'cus_unknown', 'price_unknown');
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_test_billing_secret');

    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig];

    $this->call('POST', '/api/webhooks/stripe', [], [], [], $headers, $body)->assertOk();
    $this->call('POST', '/api/webhooks/stripe', [], [], [], $headers, $body)->assertOk();

    expect(DB::table('billing.webhook_events')
        ->where('stripe_event_id', $event['id'])
        ->count())->toBe(1);
});
