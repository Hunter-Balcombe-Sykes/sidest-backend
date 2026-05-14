<?php

use App\Jobs\Stripe\ReconcileStuckPayoutsJob;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

// STRP-3: daily reconciliation for payouts stuck in 'processing' longer than 3 days.
// Without this, a lost payment_intent.succeeded webhook leaves the payout in 'processing'
// forever — the daily sweep re-dispatches jobs but each job immediately no-ops on the
// processing guard. This job asks Stripe directly: "what is the current PI status?"

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow(null);
});

beforeEach(function () {
    setupProfessionalsTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
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
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT,
        processed_at TEXT,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        created_at TEXT,
        updated_at TEXT
    )');
});

function reconcile_seedPayout(string $id, array $overrides = []): CommissionPayout
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
        'payment_intent_id' => 'pi_test_'.$id,
        'status' => 'processing',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 300,
        'net_payout_cents' => 9700,
        'currency_code' => 'AUD',
        'charge_cents' => 0,
        'retry_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return CommissionPayout::find($id);
}

// ─── PI succeeded: marks payout completed ────────────────────────────────────

it('calls markPaymentIntentSucceeded when Stripe reports pi status as succeeded', function () {
    \Illuminate\Support\Carbon::setTestNow(now());

    reconcile_seedPayout('p_succeeded', [
        'payment_intent_id' => 'pi_succeeded',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $pi = (object) [
        'id' => 'pi_succeeded',
        'status' => 'succeeded',
        'latest_charge' => 'ch_recovered',
    ];

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->shouldReceive('retrieve')
        ->with('pi_succeeded')
        ->andReturn($pi);

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentSucceeded')
        ->once()
        ->withArgs(fn ($payout, $chargeId) => $payout->id === 'p_succeeded' && $chargeId === 'ch_recovered');

    $job = new ReconcileStuckPayoutsJob($stripe, $payoutService);
    $job->handle();
});

// ─── PI failed: marks payout failed ──────────────────────────────────────────

it('calls markPaymentIntentFailed when Stripe reports pi status as requires_payment_method', function () {
    \Illuminate\Support\Carbon::setTestNow(now());

    reconcile_seedPayout('p_failed', [
        'payment_intent_id' => 'pi_failed',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $pi = (object) [
        'id' => 'pi_failed',
        'status' => 'requires_payment_method',
        'latest_charge' => null,
    ];

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->shouldReceive('retrieve')
        ->with('pi_failed')
        ->andReturn($pi);

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentFailed')
        ->once()
        ->withArgs(fn ($payout) => $payout->id === 'p_failed');

    $job = new ReconcileStuckPayoutsJob($stripe, $payoutService);
    $job->handle();
});

// ─── PI still processing: heartbeat log only ─────────────────────────────────

it('logs a heartbeat and leaves the payout untouched when Stripe reports pi still processing', function () {
    \Illuminate\Support\Carbon::setTestNow(now());

    reconcile_seedPayout('p_heartbeat', [
        'payment_intent_id' => 'pi_heartbeat',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $pi = (object) ['id' => 'pi_heartbeat', 'status' => 'processing'];

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->shouldReceive('retrieve')
        ->with('pi_heartbeat')
        ->andReturn($pi);

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldNotReceive('markPaymentIntentSucceeded');
    $payoutService->shouldNotReceive('markPaymentIntentFailed');

    Log::spy();

    $job = new ReconcileStuckPayoutsJob($stripe, $payoutService);
    $job->handle();

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($msg) => str_contains($msg, 'stripe.reconcile.still_processing'))
        ->once();
});

// ─── Freshly-stuck payouts are skipped (3-day threshold) ────────────────────

it('skips payouts updated within the 3-day BECS threshold', function () {
    \Illuminate\Support\Carbon::setTestNow(now());

    reconcile_seedPayout('p_fresh', [
        'payment_intent_id' => 'pi_fresh',
        'updated_at' => now()->subDays(1)->toDateTimeString(), // only 1 day old, within threshold
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->shouldNotReceive('retrieve');

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldNotReceive('markPaymentIntentSucceeded');
    $payoutService->shouldNotReceive('markPaymentIntentFailed');

    $job = new ReconcileStuckPayoutsJob($stripe, $payoutService);
    $job->handle();
});

// ─── requires_capture is NOT a terminal failure — should heartbeat, not fail ──

it('treats requires_capture as still-processing, not a terminal failure', function () {
    \Illuminate\Support\Carbon::setTestNow(now());

    reconcile_seedPayout('p_capture', [
        'payment_intent_id' => 'pi_capture',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $pi = (object) ['id' => 'pi_capture', 'status' => 'requires_capture'];

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->shouldReceive('retrieve')
        ->with('pi_capture')
        ->andReturn($pi);

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldNotReceive('markPaymentIntentFailed');
    $payoutService->shouldNotReceive('markPaymentIntentSucceeded');

    $job = new ReconcileStuckPayoutsJob($stripe, $payoutService);
    $job->handle();
});

// ─── Expanded latest_charge object form extracts charge ID correctly ──────────

it('extracts charge_id from an expanded latest_charge object on success', function () {
    \Illuminate\Support\Carbon::setTestNow(now());

    reconcile_seedPayout('p_expanded', [
        'payment_intent_id' => 'pi_expanded',
        'updated_at' => now()->subDays(4)->toDateTimeString(),
    ]);

    $pi = (object) [
        'id' => 'pi_expanded',
        'status' => 'succeeded',
        'latest_charge' => (object) ['id' => 'ch_expanded_123'],
    ];

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->shouldReceive('retrieve')
        ->with('pi_expanded')
        ->andReturn($pi);

    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $payoutService->shouldReceive('markPaymentIntentSucceeded')
        ->once()
        ->withArgs(fn ($payout, $chargeId) => $payout->id === 'p_expanded' && $chargeId === 'ch_expanded_123');

    $job = new ReconcileStuckPayoutsJob($stripe, $payoutService);
    $job->handle();
});

// ─── Non-processing payouts are not queried ──────────────────────────────────

it('ignores payouts that are not in processing status', function () {
    \Illuminate\Support\Carbon::setTestNow(now());

    reconcile_seedPayout('p_completed', [
        'payment_intent_id' => 'pi_completed',
        'status' => 'completed',
        'updated_at' => now()->subDays(10)->toDateTimeString(),
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->shouldNotReceive('retrieve');

    $payoutService = Mockery::mock(CommissionPayoutService::class);

    $job = new ReconcileStuckPayoutsJob($stripe, $payoutService);
    $job->handle();
});
