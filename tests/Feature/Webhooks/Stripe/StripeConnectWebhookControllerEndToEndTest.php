<?php

use App\Http\Controllers\Api\Webhooks\StripeConnectWebhookController;
use App\Services\Stripe\CommissionVoidService;
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

    // Phase 4+: flushHeldCommissions is a no-op (orders are 'approved' on creation;
    // no held state to flush). The transaction rollback contract is still tested below.
    $this->call('POST', '/api/webhooks/stripe-connect', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $row = DB::table('core.professionals')->where('id', $proId)->first();
    expect($row->stripe_connect_status)->toBe('active');
});

// V5-010: account.application.deauthorized sets status to 'disconnected'
it('stripe connect — account.application.deauthorized sets stripe_connect_status to disconnected', function () {
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
    expect($row->stripe_connect_status)->toBe('disconnected');
});

// V5-009: atomic flush — if flushHeldCommissions throws, the status update rolls back
it('stripe connect — status update rolls back when flushHeldCommissions throws', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'aff_rollback',
        'professional_type' => 'professional',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_rollback',
        'stripe_connect_status' => 'onboarding',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->mock(CommissionVoidService::class, function ($mock) {
        $mock->shouldReceive('flushHeldCommissions')->andThrow(new \RuntimeException('flush failed'));
    });

    // Build an account.updated event that would transition onboarding → active
    $event = \Stripe\Event::constructFrom([
        'id' => 'evt_rollback_test',
        'object' => 'event',
        'account' => 'acct_rollback',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'account.updated',
        'data' => [
            'object' => [
                'id' => 'acct_rollback',
                'object' => 'account',
                'charges_enabled' => true,
                'payouts_enabled' => true,
                'details_submitted' => true,
                'requirements' => ['currently_due' => []],
            ],
        ],
        'livemode' => false,
    ]);

    $controller = app(StripeConnectWebhookController::class);

    expect(fn () => $controller->handleParsedEvent($event))->toThrow(\RuntimeException::class);

    // Status must be rolled back — professional stays in 'onboarding'
    $row = DB::table('core.professionals')->where('id', $proId)->first();
    expect($row->stripe_connect_status)->toBe('onboarding');
});

// ============================================================
// transfer.reversed — dedicated handler tests
// ============================================================

describe('transfer.reversed webhook', function () {
    beforeEach(function () {
        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
            id TEXT PRIMARY KEY,
            brand_professional_id TEXT,
            affiliate_professional_id TEXT,
            stripe_transfer_id TEXT,
            status TEXT NOT NULL DEFAULT \'pending\',
            gross_commission_cents INTEGER NOT NULL DEFAULT 0,
            platform_fee_cents INTEGER NOT NULL DEFAULT 0,
            net_payout_cents INTEGER NOT NULL DEFAULT 0,
            currency_code TEXT NOT NULL DEFAULT \'AUD\',
            failure_reason TEXT,
            failure_code TEXT,
            needs_manual_refund INTEGER NOT NULL DEFAULT 0,
            ledger_entry_count INTEGER NOT NULL DEFAULT 0,
            wallet_debit_cents INTEGER DEFAULT 0,
            charge_cents INTEGER DEFAULT 0,
            retry_count INTEGER NOT NULL DEFAULT 0,
            void_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
    });

    // Helper: build a transfer.reversed Stripe Event via handleParsedEvent (no HMAC needed)
    function makeTransferReversedEvent(string $payoutId, string $transferId = 'tr_test'): \Stripe\Event
    {
        return \Stripe\Event::constructFrom([
            'id' => 'evt_reversed_'.Str::random(10),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'type' => 'transfer.reversed',
            'data' => [
                'object' => [
                    'id' => $transferId,
                    'object' => 'transfer',
                    'metadata' => ['sidest_payout_id' => $payoutId],
                ],
            ],
            'livemode' => false,
        ]);
    }

    function insertWebhookPayout(string $id, string $status): void
    {
        DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
            'id' => $id,
            'status' => $status,
            'gross_commission_cents' => 10000,
            'platform_fee_cents' => 300,
            'net_payout_cents' => 9700,
            'currency_code' => 'AUD',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    it('marks a completed payout as reversed with needs_manual_refund', function () {
        insertWebhookPayout('payout-rev-1', 'completed');

        $controller = app(StripeConnectWebhookController::class);
        $controller->handleParsedEvent(makeTransferReversedEvent('payout-rev-1'));

        $row = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'payout-rev-1')->first();
        expect($row->status)->toBe('reversed');
        expect($row->failure_code)->toBe('transfer_reversed');
        expect((bool) $row->needs_manual_refund)->toBeTrue();
    });

    it('marks an in-flight (transferring) payout as reversed', function () {
        insertWebhookPayout('payout-rev-2', 'transferring');

        $controller = app(StripeConnectWebhookController::class);
        $controller->handleParsedEvent(makeTransferReversedEvent('payout-rev-2'));

        $row = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'payout-rev-2')->first();
        expect($row->status)->toBe('reversed');
        expect($row->failure_code)->toBe('transfer_reversed');
        expect((bool) $row->needs_manual_refund)->toBeTrue();
    });

    it('is idempotent — duplicate transfer.reversed on already-reversed payout is a no-op', function () {
        insertWebhookPayout('payout-rev-3', 'reversed');
        DB::connection('pgsql')->table('commerce.commission_payouts')
            ->where('id', 'payout-rev-3')
            ->update(['failure_code' => 'transfer_reversed', 'needs_manual_refund' => 1]);

        $controller = app(StripeConnectWebhookController::class);
        $controller->handleParsedEvent(makeTransferReversedEvent('payout-rev-3'));

        $row = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'payout-rev-3')->first();
        expect($row->status)->toBe('reversed');
    });

    it('is a no-op when the transfer metadata has no sidest_payout_id', function () {
        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_reversed_noid',
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'type' => 'transfer.reversed',
            'data' => ['object' => ['id' => 'tr_noid', 'object' => 'transfer', 'metadata' => []]],
            'livemode' => false,
        ]);

        $controller = app(StripeConnectWebhookController::class);
        // Should not throw
        $response = $controller->handleParsedEvent($event);
        expect($response->getStatusCode())->toBe(200);
    });

    it('transfer.reversed uses failure_code transfer_reversed, not transfer_failed_webhook', function () {
        insertWebhookPayout('payout-rev-5', 'completed');

        $controller = app(StripeConnectWebhookController::class);
        $controller->handleParsedEvent(makeTransferReversedEvent('payout-rev-5'));

        $row = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'payout-rev-5')->first();
        expect($row->failure_code)->toBe('transfer_reversed')
            ->not->toBe('transfer_failed_webhook');
    });

    it('transfer.failed still skips completed payouts (guard unchanged)', function () {
        insertWebhookPayout('payout-fail-1', 'completed');

        $event = \Stripe\Event::constructFrom([
            'id' => 'evt_failed_'.Str::random(10),
            'object' => 'event',
            'api_version' => '2024-04-10',
            'created' => time(),
            'type' => 'transfer.failed',
            'data' => [
                'object' => [
                    'id' => 'tr_failed',
                    'object' => 'transfer',
                    'metadata' => ['sidest_payout_id' => 'payout-fail-1'],
                ],
            ],
            'livemode' => false,
        ]);

        $controller = app(StripeConnectWebhookController::class);
        $controller->handleParsedEvent($event);

        // completed payouts must NOT be touched by transfer.failed
        $row = DB::connection('pgsql')->table('commerce.commission_payouts')->where('id', 'payout-fail-1')->first();
        expect($row->status)->toBe('completed');
    });
});
