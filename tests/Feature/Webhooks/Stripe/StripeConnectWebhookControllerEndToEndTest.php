<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

    // Idempotency table — shared with StripeWebhookController
    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');

    // Add Stripe Connect columns to professionals (setupProfessionalsTable doesn't include them)
    try {
        $conn->statement('ALTER TABLE core.professionals ADD COLUMN stripe_connect_account_id TEXT NULL');
    } catch (\Throwable) {
    }
    try {
        $conn->statement('ALTER TABLE core.professionals ADD COLUMN stripe_connect_status TEXT NULL');
    } catch (\Throwable) {
    }

    Config::set('services.stripe.connect_webhook_secret', 'whsec_connect_test');
    Config::set('services.stripe.webhook_secret', 'whsec_billing_test');
});

function realStripeAccountUpdatedEvent(string $accountId, bool $detailsSubmitted = true): array
{
    return [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'account' => $accountId,  // top-level HMAC-signed account — must match data.object.id
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'account.updated',
        'data' => [
            'object' => [
                'id' => $accountId,  // must equal top-level 'account' key
                'object' => 'account',
                'charges_enabled' => true,
                'payouts_enabled' => true,
                'details_submitted' => $detailsSubmitted,
                'requirements' => [
                    'currently_due' => [],
                    'past_due' => [],
                    'pending_verification' => [],
                ],
            ],
        ],
        'livemode' => false,
    ];
}

it('stripe connect — rejects 400 when Stripe-Signature is missing', function () {
    $this->postJson('/api/webhooks/stripe-connect', ['type' => 'account.updated'])
        ->assertStatus(400)
        ->assertJson(['error' => 'Missing signature']);
});

it('stripe connect — rejects 400 when neither connect nor billing secret matches', function () {
    $event = realStripeAccountUpdatedEvent('acct_real');
    $body = json_encode($event);
    $sig = signStripeBody($body, 'wrong-secret');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)
        ->assertStatus(400)
        ->assertJson(['error' => 'Invalid signature']);
});

it('stripe connect — account_mismatch returns 400 (HMAC-signed account != data.object.id)', function () {
    // event['account'] = 'acct_real' (HMAC-signed top-level) but
    // event['data']['object']['id'] = 'acct_attacker' — mismatch triggers rejection
    $event = realStripeAccountUpdatedEvent('acct_real');
    $event['data']['object']['id'] = 'acct_attacker';
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_connect_test');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)
        ->assertStatus(400)
        ->assertJson(['error' => 'account_mismatch']);
});

it('stripe connect — valid account.updated transitions stripe_connect_status', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'aff1',
        'professional_type' => 'professional',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_real',
        'stripe_connect_status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $event = realStripeAccountUpdatedEvent('acct_real', detailsSubmitted: true);
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_connect_test');

    // CommissionVoidService::flushHeldCommissions is called on pending→active but
    // is wrapped in try/catch — retail tables missing in tests are silently swallowed.
    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $row = DB::table('core.professionals')->where('id', $proId)->first();
    expect($row->stripe_connect_status)->toBe('active');
});
