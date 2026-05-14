<?php

use App\Http\Controllers\Api\Webhooks\StripeConnectWebhookController;
use App\Jobs\Stripe\SyncBrandPaymentMethodFromCheckoutSessionJob;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();
    setupCommissionLedgerEntriesTable();

    $conn = DB::connection('pgsql');

    // Idempotency table — shared with StripeWebhookController
    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');

    // stripe_connect_account_id and stripe_connect_status are included in setupProfessionalsTable.

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

it('stripe connect — rejects 400 when event payload is structurally incomplete (missing data)', function () {
    // Valid HMAC, valid JSON, but missing 'data' — our structural check should reject before storage.
    $body = json_encode([
        'id' => 'evt_'.Str::random(20),
        'type' => 'account.updated',
    ]);
    $sig = signStripeBody($body, 'whsec_connect_test');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)
        ->assertStatus(400)
        ->assertJson(['error' => 'Invalid payload structure']);
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

it('stripe connect — valid account.updated transitions stripe_connect_status via syncAccountStatus', function () {
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

    // Under v2 Option A the connect-scope account.updated handler busts the cache and
    // delegates to StripeConnectService::syncAccountStatus, which retrieves the v2 account
    // from Stripe. We stub the service so the test stays unit-isolated. The stub also
    // performs the status persistence that the real implementation does.
    $mockService = Mockery::mock(\App\Services\Stripe\StripeConnectService::class)->makePartial();
    $mockService->shouldReceive('syncAccountStatus')
        ->once()
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

    $event = realStripeAccountUpdatedEvent('acct_real', detailsSubmitted: true);
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_connect_test');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $row = DB::table('core.professionals')->where('id', $proId)->first();
    expect($row->stripe_connect_status)->toBe('active');
});

// Under v2 Option A: account.application.deauthorized nulls stripe_connect_account_id
// + sets stripe_connect_status='not_connected' (the 'disconnected' enum value is gone).
it('stripe connect — account.application.deauthorized resets connect state to not_connected', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'aff_deauth',
        'professional_type' => 'professional',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_deauth',
        'stripe_connect_status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // event->account = connected account; data.object is an Application (id differs by design)
    $payload = json_encode([
        'id' => 'evt_deauth_'.Str::random(10),
        'object' => 'event',
        'account' => 'acct_deauth',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'account.application.deauthorized',
        'data' => [
            'object' => [
                'id' => 'ca_some_app_id',
                'object' => 'application',
                'name' => 'Partna',
            ],
        ],
        'livemode' => false,
    ]);
    $sig = signStripeBody($payload, 'whsec_connect_test');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $payload)->assertOk();

    $row = DB::table('core.professionals')->where('id', $proId)->first();
    expect($row->stripe_connect_status)->toBe('not_connected');
    expect($row->stripe_connect_account_id)->toBeNull();
});

// ============================================================
// checkout.session.completed — mode branching tests
// ============================================================

describe('checkout.session.completed webhook', function () {
    it('mode=setup dispatches SyncBrandPaymentMethodFromCheckoutSessionJob (Master Pattern 16)', function () {
        Bus::fake([SyncBrandPaymentMethodFromCheckoutSessionJob::class]);

        $proId = (string) Str::uuid();
        DB::table('core.professionals')->insert([
            'id' => $proId,
            'handle' => 'brand_checkout',
            'professional_type' => 'brand',
            'status' => 'active',
            'stripe_connect_account_id' => 'acct_brand_test',
            'stripe_connect_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The service must NOT be called synchronously — the whole point of
        // Master Pattern 16 is to keep the Stripe Session::retrieve off the
        // webhook handler's hot path.
        $mockService = Mockery::mock(StripeConnectService::class);
        $mockService->shouldNotReceive('syncBrandPaymentMethodFromCheckoutSession');
        app()->instance(StripeConnectService::class, $mockService);

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_checkout_'.Str::random(10),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_session_1',
                    'object' => 'checkout.session',
                    'mode' => 'setup',
                    'metadata' => ['sidest_professional_id' => $proId],
                ],
            ],
            'livemode' => false,
        ]);

        $controller = app(StripeConnectWebhookController::class);
        $response = $controller->handleParsedEvent($event);
        expect($response->getStatusCode())->toBe(200);

        Bus::assertDispatched(
            SyncBrandPaymentMethodFromCheckoutSessionJob::class,
            fn (SyncBrandPaymentMethodFromCheckoutSessionJob $job) => $job->professionalId === $proId
                && $job->checkoutSessionId === 'cs_test_session_1'
                && $job->queue === 'integrations',
        );
    });

    it('mode=payment is ignored (top-ups removed) without throwing or dispatching the sync job', function () {
        Bus::fake([SyncBrandPaymentMethodFromCheckoutSessionJob::class]);

        $proId = (string) Str::uuid();
        DB::table('core.professionals')->insert([
            'id' => $proId,
            'handle' => 'brand_topup_legacy',
            'professional_type' => 'brand',
            'status' => 'active',
            'stripe_connect_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // No service interaction expected — the payment-mode arm is now a logged no-op.
        $mockService = Mockery::mock(StripeConnectService::class);
        $mockService->shouldNotReceive('syncBrandPaymentMethodFromCheckoutSession');
        app()->instance(StripeConnectService::class, $mockService);

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_checkout_pay_'.Str::random(10),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_payment_1',
                    'object' => 'checkout.session',
                    'mode' => 'payment',
                    'metadata' => ['professional_id' => $proId],
                ],
            ],
            'livemode' => false,
        ]);

        $controller = app(StripeConnectWebhookController::class);
        $response = $controller->handleParsedEvent($event);
        expect($response->getStatusCode())->toBe(200);

        Bus::assertNotDispatched(SyncBrandPaymentMethodFromCheckoutSessionJob::class);
    });

    it('missing professional_id logs a warning and returns 200', function () {
        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_checkout_noid_'.Str::random(10),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_noid',
                    'object' => 'checkout.session',
                    'mode' => 'setup',
                    'metadata' => [],
                ],
            ],
            'livemode' => false,
        ]);

        $controller = app(StripeConnectWebhookController::class);
        $response = $controller->handleParsedEvent($event);
        expect($response->getStatusCode())->toBe(200);
    });
});

// ============================================================
// STRP-2: delete-on-failure so Stripe can retry
// ============================================================

it('stripe connect — deletes webhook_event row when handler throws, allowing Stripe to retry', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'aff_throw',
        'handle_lc' => 'aff_throw',
        'display_name' => 'Aff Throw',
        'professional_type' => 'affiliate',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_throw',
        'stripe_connect_status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mockService = Mockery::mock(\App\Services\Stripe\StripeConnectService::class)->makePartial();
    $mockService->shouldReceive('syncAccountStatus')
        ->andThrow(new \RuntimeException('Transient write failure'));
    app()->instance(\App\Services\Stripe\StripeConnectService::class, $mockService);

    $event = realStripeAccountUpdatedEvent('acct_throw');
    $body = json_encode($event);
    $sig = signStripeBody($body, 'whsec_connect_test');

    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertStatus(500);

    // Row deleted so Stripe's retry is not silenced by the dedup guard
    expect(DB::table('billing.webhook_events')->where('stripe_event_id', $event['id'])->count())->toBe(0);
});
