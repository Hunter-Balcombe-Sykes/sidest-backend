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
    setupCommissionLedgerEntriesTable();

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

    // Production schema uses uuid/jsonb/timestamptz; SQLite-in-memory only supports TEXT.
    // The UNIQUE constraint on stripe_event_id is what the dedupe logic actually relies on.
    // received_at = set on dedup row creation; processed_at = set after handler completes.
    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT NOT NULL UNIQUE,
        event_type TEXT NOT NULL,
        payload TEXT,
        received_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        processed_at TEXT NULL
    )');
});

it('skips processing when stripe_event_id already logged', function () {
    // Pre-seed: this event was already received (and processed) in a prior delivery.
    DB::table('billing.webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'stripe_event_id' => 'evt_duplicate_123',
        'event_type' => 'account.updated',
        'payload' => json_encode(['id' => 'evt_duplicate_123']),
        'received_at' => now()->toDateTimeString(),
        'processed_at' => now()->toDateTimeString(),
    ]);

    $professional = Professional::create([
        'id' => (string) Str::uuid(),
        'handle' => 'p1',
        'handle_lc' => 'p1',
        'display_name' => 'P1',
        'professional_type' => 'affiliate',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_xyz',
        'stripe_connect_status' => 'onboarding',
    ]);

    $payload = json_encode([
        'id' => 'evt_duplicate_123',
        'type' => 'account.updated',
        'account' => 'acct_xyz',   // top-level field must match data.object.id (real Stripe behavior)
        'data' => ['object' => [
            'id' => 'acct_xyz',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
        ]],
    ]);

    $signingSecret = 'whsec_test_dedupe';
    config(['services.stripe.connect_webhook_secret' => $signingSecret]);
    config(['services.stripe.webhook_secret' => null]);

    $timestamp = time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $signingSecret);

    $response = $this->call(
        'POST',
        '/api/webhooks/stripe-connect',
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
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

it('deletes the dedup row when the handler throws so Stripe can retry (STRP-C delete-on-failure)', function () {
    // Critical reliability fix: prior to STRP-C the WebhookEvent row was committed
    // BEFORE the handler ran. A transient handler failure (DB deadlock, transient
    // Stripe API error, etc.) returned 500 to Stripe — but on Stripe's retry the
    // dedup row already existed, so the webhook was acked immediately with 200 and
    // the underlying event was permanently silenced.
    $mockService = Mockery::mock(\App\Services\Stripe\StripeConnectService::class)->makePartial();
    $mockService->shouldReceive('syncAccountStatus')
        ->andThrow(new \RuntimeException('Simulated transient handler failure'));
    app()->instance(\App\Services\Stripe\StripeConnectService::class, $mockService);

    Professional::create([
        'id' => (string) Str::uuid(),
        'handle' => 'p_throw',
        'handle_lc' => 'p_throw',
        'display_name' => 'P throw',
        'professional_type' => 'affiliate',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_throw_test',
        'stripe_connect_status' => 'onboarding',
    ]);

    $payload = json_encode([
        'id' => 'evt_handler_throws',
        'type' => 'account.updated',
        'account' => 'acct_throw_test',
        'data' => ['object' => ['id' => 'acct_throw_test']],
    ]);

    $signingSecret = 'whsec_test_dedupe';
    config(['services.stripe.connect_webhook_secret' => $signingSecret]);
    config(['services.stripe.webhook_secret' => null]);

    $timestamp = time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $signingSecret);

    // Laravel catches the RuntimeException and renders a 500. We don't assert on the
    // response shape here — the critical assertion is that the dedup row was DELETED
    // so Stripe's next retry can re-attempt processing instead of being acked.
    $this->call(
        'POST',
        '/api/webhooks/stripe-connect',
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        $payload
    );

    expect(DB::table('billing.webhook_events')->where('stripe_event_id', 'evt_handler_throws')->count())->toBe(0);
});

it('processes a fresh event and records it in webhook_events', function () {
    $professional = Professional::create([
        'id' => (string) Str::uuid(),
        'handle' => 'p2',
        'handle_lc' => 'p2',
        'display_name' => 'P2',
        'professional_type' => 'affiliate',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_fresh',
        'stripe_connect_status' => 'onboarding',
    ]);

    // Under v2 Option A the connect-scope account.updated handler delegates to
    // StripeConnectService::syncAccountStatus, which retrieves the v2 account from Stripe.
    // Stub it so the test doesn't need real API credentials; the stub performs the same
    // status persistence the real implementation does.
    $mockService = Mockery::mock(\App\Services\Stripe\StripeConnectService::class)->makePartial();
    $mockService->shouldReceive('syncAccountStatus')
        ->andReturnUsing(function ($pro) {
            DB::table('core.professionals')
                ->where('id', $pro->id)
                ->update(['stripe_connect_status' => 'active']);

            return [
                'status' => 'active',
                'stripe_connect_account_id' => $pro->stripe_connect_account_id,
                'card_payments_active' => true,
                'stripe_transfers_active' => true,
                'requirements' => [],
            ];
        });
    app()->instance(\App\Services\Stripe\StripeConnectService::class, $mockService);

    $payload = json_encode([
        'id' => 'evt_fresh_456',
        'type' => 'account.updated',
        'account' => 'acct_fresh',  // top-level field must match data.object.id (real Stripe behavior)
        'data' => ['object' => [
            'id' => 'acct_fresh',
        ]],
    ]);

    $signingSecret = 'whsec_test_dedupe';
    config(['services.stripe.connect_webhook_secret' => $signingSecret]);
    config(['services.stripe.webhook_secret' => null]);

    $timestamp = time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, $signingSecret);

    $response = $this->call(
        'POST',
        '/api/webhooks/stripe-connect',
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        $payload
    );

    $response->assertOk();

    // Event was recorded for future dedupe.
    expect(DB::table('billing.webhook_events')->where('stripe_event_id', 'evt_fresh_456')->count())->toBe(1);

    // Handler ran — status promoted to 'active' by the stubbed syncAccountStatus.
    expect($professional->fresh()->stripe_connect_status)->toBe('active');
});
