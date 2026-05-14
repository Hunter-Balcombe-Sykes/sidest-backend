<?php

use App\Http\Controllers\Api\Webhooks\StripePlatformWebhookController;
use App\Models\Billing\WebhookEvent;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutRefundService;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Tests for the v2 platform webhook controller covering both endpoints:
//   POST /api/webhooks/stripe-platform        → __invoke (snapshot, v1 events)
//   POST /api/webhooks/stripe-platform-thin   → thin (thin, v2 events)
//
// v2 destination-charge model: payment_intent.* events carry metadata.sidest_payout_id
// to link to the local CommissionPayout. Subscription PIs (no metadata) pass through.

beforeEach(function () {
    Config::set('services.stripe.secret_key', 'sk_test_fake');
    Config::set('services.stripe.platform_webhook_secret', 'whsec_platform_test');
    Config::set('services.stripe.platform_thin_webhook_secret', 'whsec_platform_thin_test');

    // Attach all schemas FIRST before any CREATE TABLE — otherwise 'billing.webhook_events'
    // fails with "no such database: billing" since ATTACH DATABASE per-schema hasn't run.
    attachTestSchemas();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');

    setupProfessionalsTable();
    setupCommerceOrdersTables();

    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        payment_intent_id TEXT,
        charge_id TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_commission_cents INTEGER NOT NULL DEFAULT 0,
        platform_fee_cents INTEGER NOT NULL DEFAULT 0,
        net_payout_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        failure_reason TEXT,
        failure_code TEXT,
        failure_category TEXT,
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT,
        processed_at TEXT,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
        transfer_completed_at TEXT,
        last_retry_at TEXT,
        funding_failure_count INTEGER NOT NULL DEFAULT 0,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        grace_started_at TEXT,
        next_retry_at TEXT,
        stripe_error_code TEXT,
        stripe_error_message TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.orders (
        id TEXT PRIMARY KEY,
        shopify_order_id TEXT,
        shopify_shop_domain TEXT,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        status TEXT,
        gross_cents INTEGER DEFAULT 0,
        discount_cents INTEGER DEFAULT 0,
        refund_cents INTEGER DEFAULT 0,
        net_cents INTEGER DEFAULT 0,
        commission_cents INTEGER DEFAULT 0,
        commission_rate INTEGER DEFAULT 0,
        rate_source TEXT,
        currency_code TEXT,
        payout_id TEXT,
        shopify_updated_at TEXT,
        occurred_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
        'stripe_payment_method_id TEXT',
        'stripe_payment_method_brand TEXT',
        'stripe_payment_method_last4 TEXT',
        'payout_method TEXT',
        'primary_email TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }
});

function platformWebhook_seedProfessional(string $id, array $overrides = []): Professional
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "pro-{$id}",
        'handle_lc' => "pro-{$id}",
        'display_name' => "Pro {$id}",
        'professional_type' => 'brand',
        'status' => 'active',
        'primary_email' => "{$id}@test.test",
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return Professional::find($id);
}

function platformWebhook_seedPayout(string $id, string $status = 'processing', array $overrides = []): CommissionPayout
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => 'pro-brand',
        'affiliate_professional_id' => 'pro-aff',
        'status' => $status,
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 300,
        'net_payout_cents' => 9700,
        'currency_code' => 'AUD',
        'ledger_entry_count' => 1,
        'eligible_after' => $now,
        'charge_cents' => 0,
        'retry_count' => 0,
        'needs_manual_refund' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return CommissionPayout::find($id);
}

/**
 * Register payout/refund service mocks in the container so the controller resolved by the
 * route picks them up. Returns the freshly-constructed controller (callers that hit HTTP
 * don't use it directly, but unit-style invocations may).
 */
function platformWebhook_makeController(
    ?CommissionPayoutService $payoutService = null,
    ?CommissionPayoutRefundService $refundService = null,
): StripePlatformWebhookController {
    $payoutService ??= Mockery::mock(CommissionPayoutService::class)->shouldIgnoreMissing();
    $refundService ??= Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();

    app()->instance(CommissionPayoutService::class, $payoutService);
    app()->instance(CommissionPayoutRefundService::class, $refundService);

    return app(StripePlatformWebhookController::class);
}

/**
 * Build a signed webhook payload that passes HMAC verification.
 */
function platformWebhook_signEvent(array $event, string $secret): string
{
    return signStripeBody(json_encode($event), $secret);
}

function platformWebhook_postSnapshot(array $event): \Illuminate\Testing\TestResponse
{
    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_test');

    return test()->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body);
}

function platformWebhook_postThin(array $event): \Illuminate\Testing\TestResponse
{
    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_thin_test');

    return test()->call('POST', '/api/webhooks/stripe-platform-thin', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body);
}

function platformWebhook_buildEvent(string $type, array $data): array
{
    return [
        'id' => 'evt_'.Str::random(20),
        'object' => 'event',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => $type,
        'data' => [
            'object' => $data,
        ],
        'livemode' => false,
    ];
}

// ============================================================
// Snapshot endpoint — signature & dedup
// ============================================================

it('returns 400 when Stripe-Signature header is missing from snapshot', function () {
    $this->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['id' => 'evt_test']))->assertStatus(400);
});

it('returns 400 when Stripe-Signature does not match the body (tampered payload)', function () {
    $event = platformWebhook_buildEvent('payment_intent.succeeded', ['id' => 'pi_test']);
    $body = json_encode($event);

    // Sign with the WRONG secret so the signature is structurally valid but doesn't verify.
    // This is the realistic attack vector — a fake "t=...,v1=..." string would also fail,
    // but signing-with-wrong-secret hits the actual HMAC comparison path in the SDK.
    $wrongSig = signStripeBody($body, 'whsec_attacker_secret');

    $this->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $wrongSig,
    ], $body)->assertStatus(400);
});

it('idempotently no-ops on duplicate snapshot event (service called once, not twice)', function () {
    platformWebhook_seedProfessional('pro-brand');
    platformWebhook_seedProfessional('pro-aff');
    platformWebhook_seedPayout('p_dup', 'processing');

    // Mock the service so we can assert it's called EXACTLY once across two webhook deliveries.
    // The first delivery should invoke markPaymentIntentSucceeded; the second is a dedup
    // hit and must short-circuit before reaching the handler.
    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentSucceeded')->once();
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    platformWebhook_makeController($payoutService, $refundService);

    $event = platformWebhook_buildEvent('payment_intent.succeeded', [
        'id' => 'pi_dup_test',
        'metadata' => ['sidest_payout_id' => 'p_dup'],
        'latest_charge' => 'ch_dup',
    ]);

    platformWebhook_postSnapshot($event)->assertOk();
    platformWebhook_postSnapshot($event)->assertOk();

    // Single row in webhook_events confirms the dedup table did its job.
    expect(WebhookEvent::where('stripe_event_id', $event['id'])->count())->toBe(1);
});

// ============================================================
// payment_intent.succeeded
// ============================================================

it('calls markPaymentIntentSucceeded when payment_intent.succeeded has sidest_payout_id', function () {
    platformWebhook_seedProfessional('pro-brand');
    platformWebhook_seedProfessional('pro-aff');
    $payout = platformWebhook_seedPayout('p_success', 'processing');

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentSucceeded')
        ->once()
        ->withArgs(function ($p, $chargeId) {
            return $p->id === 'p_success' && $chargeId === 'ch_success_123';
        });

    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    $event = platformWebhook_buildEvent('payment_intent.succeeded', [
        'id' => 'pi_success_test',
        'metadata' => ['sidest_payout_id' => 'p_success'],
        'latest_charge' => 'ch_success_123',
    ]);

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_test');

    $this->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();
});

it('passes through payment_intent.succeeded without sidest_payout_id metadata', function () {
    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldNotReceive('markPaymentIntentSucceeded');

    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    $event = platformWebhook_buildEvent('payment_intent.succeeded', [
        'id' => 'pi_sub_test',
        'metadata' => [],
    ]);

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_test');

    $this->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();
});

// ============================================================
// payment_intent.payment_failed
// ============================================================

it('calls markPaymentIntentFailed with error details from last_payment_error', function () {
    platformWebhook_seedProfessional('pro-brand');
    platformWebhook_seedProfessional('pro-aff');
    $payout = platformWebhook_seedPayout('p_fail', 'processing');

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentFailed')
        ->once()
        ->withArgs(function ($p, string $code, string $message) {
            return $p->id === 'p_fail'
                && $code === 'card_declined'
                && str_contains($message, 'insufficient funds');
        });

    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    $event = platformWebhook_buildEvent('payment_intent.payment_failed', [
        'id' => 'pi_fail_test',
        'metadata' => ['sidest_payout_id' => 'p_fail'],
        'last_payment_error' => [
            'code' => 'card_declined',
            'message' => 'Your card has insufficient funds.',
        ],
    ]);

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_test');

    $this->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();
});

it('uses decline_code as fallback when last_payment_error has no code', function () {
    platformWebhook_seedProfessional('pro-brand');
    platformWebhook_seedProfessional('pro-aff');
    $payout = platformWebhook_seedPayout('p_fail2', 'processing');

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentFailed')
        ->once()
        ->withArgs(function ($p, string $code) {
            return $code === 'do_not_honor';
        });

    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    $event = platformWebhook_buildEvent('payment_intent.payment_failed', [
        'id' => 'pi_fail2_test',
        'metadata' => ['sidest_payout_id' => 'p_fail2'],
        'last_payment_error' => [
            'decline_code' => 'do_not_honor',
            'message' => 'Declined.',
        ],
    ]);

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_test');

    $this->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();
});

// ============================================================
// charge.refunded
// ============================================================

it('passes through charge.refunded for subscription billing charges', function () {
    $payoutService = Mockery::mock(CommissionPayoutService::class)->shouldIgnoreMissing();
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    // No sidest_payout_id → passes through as subscription billing.
    $event = platformWebhook_buildEvent('charge.refunded', [
        'id' => 'ch_sub_refund',
        'metadata' => [],
        'amount_refunded' => 5000,
        'refunded' => true,
    ]);

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_test');

    $this->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();
});

// ============================================================
// charge.dispute.created
// ============================================================

it('flags payout for manual review on charge.dispute.created via charge_id lookup', function () {
    platformWebhook_seedProfessional('pro-brand');
    platformWebhook_seedProfessional('pro-aff');
    $payout = platformWebhook_seedPayout('p_dispute', 'completed', [
        'charge_id' => 'ch_disputed_charge',
    ]);

    $payoutService = Mockery::mock(CommissionPayoutService::class)->shouldIgnoreMissing();
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    $event = platformWebhook_buildEvent('charge.dispute.created', [
        'id' => 'dp_dispute_test',
        'charge' => 'ch_disputed_charge',
        'reason' => 'fraudulent',
        'metadata' => [],
    ]);

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_test');

    $this->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $fresh = $payout->fresh();
    expect($fresh->needs_manual_refund)->toBeTrue()
        ->and($fresh->failure_code)->toBe('dispute_opened');
});

// ============================================================
// Thin endpoint — signature & dedup
// ============================================================

it('returns 400 when Stripe-Signature header is missing from thin', function () {
    $this->call('POST', '/api/webhooks/stripe-platform-thin', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['id' => 'evt_test']))->assertStatus(400);
});

it('returns 200 for a duplicate thin event', function () {
    $event = [
        'id' => 'evt_thin_dup',
        'object' => 'event',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'v2.core.account.updated',
        'data' => [
            'related_object' => ['id' => 'acct_does_not_exist', 'type' => 'account'],
            'object' => ['id' => 'acct_does_not_exist'],
        ],
        'livemode' => false,
    ];

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_thin_test');

    $this->call('POST', '/api/webhooks/stripe-platform-thin', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $this->call('POST', '/api/webhooks/stripe-platform-thin', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    expect(WebhookEvent::where('stripe_event_id', 'evt_thin_dup')->count())->toBe(1);
});

// ============================================================
// v2.core.account.updated
// ============================================================

it('forgets cache and syncs account status on v2.core.account.updated', function () {
    $pro = platformWebhook_seedProfessional('pro_v2_update', [
        'stripe_connect_account_id' => 'acct_v2_update',
        'stripe_connect_status' => 'pending',
    ]);

    // Pre-warm the cache so we can assert it's cleared.
    \Illuminate\Support\Facades\Cache::put('stripe:connect:status:acct_v2_update', ['cached' => true], 60);

    // Mock StripeConnectService::syncAccountStatus so the v2 handler doesn't hit the real
    // Stripe API. The handler calls the static forgetStatusCache (no mock needed) and then
    // resolves an instance from the container to call syncAccountStatus.
    $connectService = Mockery::mock(\App\Services\Stripe\StripeConnectService::class)->makePartial();
    $connectService->shouldReceive('syncAccountStatus')->once()->andReturn([
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_v2_update',
        'card_payments_active' => true,
        'stripe_transfers_active' => true,
        'requirements' => [],
    ]);
    app()->instance(\App\Services\Stripe\StripeConnectService::class, $connectService);

    $payoutService = Mockery::mock(CommissionPayoutService::class)->shouldIgnoreMissing();
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    $event = [
        'id' => 'evt_v2_upd',
        'object' => 'event',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'v2.core.account.updated',
        'data' => [
            'related_object' => ['id' => 'acct_v2_update', 'type' => 'account'],
            'object' => ['id' => 'acct_v2_update'],
        ],
        'livemode' => false,
    ];

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_thin_test');

    $this->call('POST', '/api/webhooks/stripe-platform-thin', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    // Cache should be cleared. The actual sync requires a real Stripe round-trip
    // which we can't mock in a controller test, but the forget is verified.
    expect(\Illuminate\Support\Facades\Cache::get('stripe:connect:status:acct_v2_update'))->toBeNull();
});

// ============================================================
// v2.core.account.closed
// ============================================================

it('nukes account fields on v2.core.account.closed', function () {
    $pro = platformWebhook_seedProfessional('pro_v2_closed', [
        'stripe_connect_account_id' => 'acct_v2_closed',
        'stripe_connect_status' => 'active',
        'stripe_payment_method_id' => 'pm_closed_test',
        'stripe_payment_method_brand' => 'visa',
        'stripe_payment_method_last4' => '4242',
        'payout_method' => 'card',
    ]);

    $payoutService = Mockery::mock(CommissionPayoutService::class)->shouldIgnoreMissing();
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    $event = [
        'id' => 'evt_v2_closed',
        'object' => 'event',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'v2.core.account.closed',
        'data' => [
            'related_object' => ['id' => 'acct_v2_closed', 'type' => 'account'],
            'object' => ['id' => 'acct_v2_closed'],
        ],
        'livemode' => false,
    ];

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_thin_test');

    $this->call('POST', '/api/webhooks/stripe-platform-thin', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();

    $fresh = $pro->fresh();
    expect($fresh->stripe_connect_account_id)->toBeNull()
        ->and($fresh->stripe_connect_status)->toBe('not_connected')
        ->and($fresh->stripe_payment_method_id)->toBeNull()
        ->and($fresh->stripe_payment_method_brand)->toBeNull()
        ->and($fresh->stripe_payment_method_last4)->toBeNull()
        ->and($fresh->payout_method)->toBeNull();
});

// ============================================================
// Thin endpoint — non-v2 events pass through
// ============================================================

it('passes through non-v2.core.account thin events', function () {
    $payoutService = Mockery::mock(CommissionPayoutService::class)->shouldIgnoreMissing();
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    $event = [
        'id' => 'evt_non_v2',
        'object' => 'event',
        'api_version' => '2024-04-10',
        'created' => time(),
        'type' => 'charge.succeeded',
        'data' => [
            'object' => ['id' => 'ch_unrelated'],
        ],
        'livemode' => false,
    ];

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_thin_test');

    $this->call('POST', '/api/webhooks/stripe-platform-thin', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();
});

// ============================================================
// Unhandled event types are logged but don't error
// ============================================================

it('returns 200 for unhandled snapshot event types', function () {
    $payoutService = Mockery::mock(CommissionPayoutService::class)->shouldIgnoreMissing();
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    $controller = platformWebhook_makeController($payoutService, $refundService);

    $event = platformWebhook_buildEvent('payment_intent.created', [
        'id' => 'pi_unhandled',
        'metadata' => [],
    ]);

    $body = json_encode($event);
    $sig = platformWebhook_signEvent($event, 'whsec_platform_test');

    $this->call('POST', '/api/webhooks/stripe-platform', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $sig,
    ], $body)->assertOk();
});

// ============================================================
// STRP-2: delete-on-failure so Stripe can retry
// ============================================================

it('deletes webhook_event row when payment_intent handler throws, allowing Stripe to retry', function () {
    platformWebhook_seedProfessional('pro-brand');
    platformWebhook_seedProfessional('pro-aff');
    platformWebhook_seedPayout('p_handler_throws', 'processing');

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentSucceeded')
        ->andThrow(new \RuntimeException('Transient DB deadlock'));
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    platformWebhook_makeController($payoutService, $refundService);

    $event = platformWebhook_buildEvent('payment_intent.succeeded', [
        'id' => 'pi_throw_test',
        'metadata' => ['sidest_payout_id' => 'p_handler_throws'],
        'latest_charge' => 'ch_throw',
    ]);

    // Handler throws → 500 returned to Stripe so it will retry
    platformWebhook_postSnapshot($event)->assertStatus(500);

    // Row must be gone so Stripe's retry is not silenced by the dedup guard
    expect(WebhookEvent::where('stripe_event_id', $event['id'])->count())->toBe(0);
});

// ============================================================
// STRP-4: clawback drift detection
// ============================================================

it('logs clawback_drift warning when Stripe refund amounts differ from local estimates by more than 1 cent', function () {
    // Set up the clawbacks table so the handler can look up estimates.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_clawbacks (
        id TEXT PRIMARY KEY,
        payout_id TEXT NOT NULL,
        order_id TEXT NOT NULL,
        shopify_refund_id TEXT,
        stripe_reversal_id TEXT,
        refund_id TEXT,
        refund_amount_cents INTEGER,
        application_fee_refund_cents INTEGER,
        transfer_reversal_cents INTEGER,
        is_partial INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        amount_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        status TEXT NOT NULL DEFAULT \'reversed\',
        failure_reason TEXT,
        metadata TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT,
        updated_at TEXT
    )');

    platformWebhook_seedProfessional('pro-brand');
    platformWebhook_seedProfessional('pro-aff');
    platformWebhook_seedPayout('p_drift', 'completed', ['charge_id' => 'ch_drift_charge']);

    // Seed the clawback row with locally-computed estimates.
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_clawbacks')->insert([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'payout_id' => 'p_drift',
        'order_id' => 'order_drift',
        'refund_id' => 're_drift_123',
        'stripe_reversal_id' => 're_drift_123',
        'refund_amount_cents' => 10000,
        'application_fee_refund_cents' => 300,  // our estimate
        'transfer_reversal_cents' => 9700,       // our estimate
        'amount_cents' => 9700,
        'currency_code' => 'AUD',
        'status' => 'reversed',
        'metadata' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Stripe's actual allocation differs by 3 cents (outside the ±1 rounding tolerance).
    $event = platformWebhook_buildEvent('charge.refunded', [
        'id' => 'ch_drift_charge',
        'metadata' => ['sidest_payout_id' => 'p_drift'],
        'amount_refunded' => 10000,
        'refunded' => true,
        'refunds' => [
            'data' => [[
                'id' => 're_drift_123',
                'amount' => 10000,
                'application_fee_refund' => ['amount' => 303],  // 3 cents more than our estimate of 300
                'transfer_reversal' => ['amount' => 9697],       // 3 cents less than our estimate of 9700
            ]],
        ],
    ]);

    $payoutService = Mockery::mock(CommissionPayoutService::class)->shouldIgnoreMissing();
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    platformWebhook_makeController($payoutService, $refundService);

    \Illuminate\Support\Facades\Log::spy();

    platformWebhook_postSnapshot($event)->assertOk();

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(fn ($channel) => str_contains($channel, 'stripe.platform.clawback_drift'))
        ->once();
});

it('does not log clawback_drift when Stripe refund amounts match local estimates within 1 cent', function () {
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_clawbacks (
        id TEXT PRIMARY KEY,
        payout_id TEXT NOT NULL,
        order_id TEXT NOT NULL,
        shopify_refund_id TEXT,
        stripe_reversal_id TEXT,
        refund_id TEXT,
        refund_amount_cents INTEGER,
        application_fee_refund_cents INTEGER,
        transfer_reversal_cents INTEGER,
        is_partial INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        amount_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        status TEXT NOT NULL DEFAULT \'reversed\',
        failure_reason TEXT,
        metadata TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT,
        updated_at TEXT
    )');

    platformWebhook_seedProfessional('pro-brand2');
    platformWebhook_seedProfessional('pro-aff2');
    platformWebhook_seedPayout('p_nodrift', 'completed', ['charge_id' => 'ch_nodrift_charge']);

    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_clawbacks')->insert([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'payout_id' => 'p_nodrift',
        'order_id' => 'order_nodrift',
        'refund_id' => 're_nodrift_123',
        'stripe_reversal_id' => 're_nodrift_123',
        'refund_amount_cents' => 10000,
        'application_fee_refund_cents' => 300,
        'transfer_reversal_cents' => 9700,
        'amount_cents' => 9700,
        'currency_code' => 'AUD',
        'status' => 'reversed',
        'metadata' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Stripe's values match exactly (within 1 cent — no drift).
    $event = platformWebhook_buildEvent('charge.refunded', [
        'id' => 'ch_nodrift_charge',
        'metadata' => ['sidest_payout_id' => 'p_nodrift'],
        'amount_refunded' => 10000,
        'refunded' => true,
        'refunds' => [
            'data' => [[
                'id' => 're_nodrift_123',
                'amount' => 10000,
                'application_fee_refund' => ['amount' => 300],  // exact match
                'transfer_reversal' => ['amount' => 9700],
            ]],
        ],
    ]);

    $payoutService = Mockery::mock(CommissionPayoutService::class)->shouldIgnoreMissing();
    $refundService = Mockery::mock(CommissionPayoutRefundService::class)->shouldIgnoreMissing();
    platformWebhook_makeController($payoutService, $refundService);

    \Illuminate\Support\Facades\Log::spy();

    platformWebhook_postSnapshot($event)->assertOk();

    \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning',
        [Mockery::on(fn ($msg) => str_contains($msg, 'clawback_drift')),
            Mockery::any()]);
});
