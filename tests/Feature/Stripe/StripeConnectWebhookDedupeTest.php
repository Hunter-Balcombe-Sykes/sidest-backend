<?php

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Dedupe-only tests for StripeConnectWebhookController. Signature gating is
// handled by the existing Stripe SDK path; this file verifies that a replayed
// event (same stripe_event_id) is short-circuited and does NOT invoke the
// inner handlers a second time.

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT DEFAULT \'not_connected\'',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT NOT NULL UNIQUE,
        event_type TEXT NOT NULL,
        payload TEXT NULL,
        processed_at TEXT NOT NULL
    )');
});

it('skips processing when stripe_event_id already logged', function () {
    // Pre-seed: this event was already processed.
    DB::table('billing.webhook_events')->insert([
        'id'             => (string) Str::uuid(),
        'stripe_event_id' => 'evt_duplicate_123',
        'event_type'     => 'account.updated',
        'payload'        => json_encode(['id' => 'evt_duplicate_123']),
        'processed_at'   => now()->toDateTimeString(),
    ]);

    $professional = Professional::create([
        'id'                       => (string) Str::uuid(),
        'handle'                   => 'p1',
        'handle_lc'                => 'p1',
        'display_name'             => 'P1',
        'professional_type'        => 'affiliate',
        'status'                   => 'active',
        'stripe_connect_account_id' => 'acct_xyz',
        'stripe_connect_status'    => 'onboarding',
    ]);

    $payload = json_encode([
        'id'   => 'evt_duplicate_123',
        'type' => 'account.updated',
        'data' => ['object' => [
            'id'                 => 'acct_xyz',
            'charges_enabled'    => true,
            'payouts_enabled'    => true,
            'details_submitted'  => true,
        ]],
    ]);

    $signingSecret = 'whsec_test_dedupe';
    config(['services.stripe.connect_webhook_secret' => $signingSecret]);
    config(['services.stripe.webhook_secret' => null]);

    $timestamp     = time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature     = hash_hmac('sha256', $signedPayload, $signingSecret);

    $response = $this->call(
        'POST',
        '/api/webhooks/stripe-connect',
        [], [], [],
        [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        $payload
    );

    $response->assertOk();
    $response->assertJson(['received' => true]);

    // Status must NOT be mutated — the handler never ran.
    expect($professional->fresh()->stripe_connect_status)->toBe('onboarding');

    // insertOrIgnore must not add a second row.
    expect(DB::table('billing.webhook_events')->where('stripe_event_id', 'evt_duplicate_123')->count())->toBe(1);
});

it('processes a fresh event and records it in webhook_events', function () {
    $professional = Professional::create([
        'id'                       => (string) Str::uuid(),
        'handle'                   => 'p2',
        'handle_lc'                => 'p2',
        'display_name'             => 'P2',
        'professional_type'        => 'affiliate',
        'status'                   => 'active',
        'stripe_connect_account_id' => 'acct_fresh',
        'stripe_connect_status'    => 'onboarding',
    ]);

    $payload = json_encode([
        'id'   => 'evt_fresh_456',
        'type' => 'account.updated',
        'data' => ['object' => [
            'id'                => 'acct_fresh',
            'charges_enabled'   => true,
            'payouts_enabled'   => true,
            'details_submitted' => true,
            'requirements'      => ['currently_due' => []],
        ]],
    ]);

    $signingSecret = 'whsec_test_dedupe';
    config(['services.stripe.connect_webhook_secret' => $signingSecret]);
    config(['services.stripe.webhook_secret' => null]);

    $timestamp     = time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature     = hash_hmac('sha256', $signedPayload, $signingSecret);

    $response = $this->call(
        'POST',
        '/api/webhooks/stripe-connect',
        [], [], [],
        [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        $payload
    );

    $response->assertOk();

    // Event was recorded for future dedupe.
    expect(DB::table('billing.webhook_events')->where('stripe_event_id', 'evt_fresh_456')->count())->toBe(1);

    // Handler ran — status promoted to 'active'.
    expect($professional->fresh()->stripe_connect_status)->toBe('active');
});
