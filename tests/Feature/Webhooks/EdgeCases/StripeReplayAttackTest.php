<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();

    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS billing");
    } catch (\Throwable) {
    }
    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');

    // billing.subscriptions is queried by handleSubscriptionUpdated even when no match exists.
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

    Config::set('services.stripe.webhook_secret', 'whsec_replay_test');
});

it('stripe — rejects an event whose Stripe-Signature timestamp is older than the tolerance window', function () {
    $event = [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['id' => 'sub_replay']],
    ];
    $body = json_encode($event);

    // Timestamp 1 hour in the past — far outside Stripe's 300s tolerance.
    $oldTimestamp = time() - 3600;
    $sig = signStripeBody($body, 'whsec_replay_test', $oldTimestamp);

    $this->call('POST', '/api/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)
        ->assertStatus(400)
        ->assertJson(['error' => 'Invalid signature']);

    expect(DB::table('billing.webhook_events')->count())->toBe(0);
});

it('stripe — accepts an event whose timestamp is within the tolerance window', function () {
    $event = [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_fresh',
            'status' => 'active',
            'customer' => 'cus_fresh',
            'cancel_at_period_end' => false,
            'current_period_start' => time(),
            'current_period_end' => time() + 86400,
            'items' => ['data' => [['id' => 'si_x', 'price' => ['id' => 'price_x']]]],
        ]],
    ];
    $body = json_encode($event);

    // Timestamp 30 seconds ago — well inside Stripe's 300s tolerance.
    $sig = signStripeBody($body, 'whsec_replay_test', time() - 30);

    $this->call('POST', '/api/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();
});
