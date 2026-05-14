<?php

use App\Jobs\Stripe\ExecuteCommissionPayoutJob;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Tests for ExecuteCommissionPayoutJob under the v2 state machine
// (pending → processing → completed | failed | cancelled).
//
// The legacy collecting/transferring states are gone. processPayoutBatch returns
// true (completed synchronously), null (BECS pre-settlement — webhook completes),
// or false (failed). The job logs the distinction between cancelled-by-revalidation
// (terminal) and parked-at-processing (awaiting webhook).

beforeEach(function () {
    setupProfessionalsTable();
    // failed() releases linked orders back to the sweep pool — needs the orders table.
    setupCommerceOrdersTables();

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
        transfer_completed_at TEXT,
        last_retry_at TEXT,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        created_at TEXT,
        updated_at TEXT
    )');

    // failed() now also deletes payout_items so the cpi_unique_order partial index
    // doesn't block the next sweep from re-claiming released orders.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY,
        payout_id TEXT,
        order_id TEXT,
        amount_cents INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');
});

function execJob_seedPayout(string $id, array $overrides = []): CommissionPayout
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
        'status' => 'pending',
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

// ─── handle() ────────────────────────────────────────────────────────────────

it('handle() skips processing when payout is not found', function () {
    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldNotReceive('processPayoutBatch');

    $job = new ExecuteCommissionPayoutJob('non-existent-id');
    $job->handle($service);
});

it('handle() skips processing when payout is already in a terminal state (BECS race defence)', function (string $status) {
    // Critical: failed/cancelled are now in the guard. Without them, a job dispatched by
    // the daily sweep that runs AFTER the BECS payment_intent.payment_failed webhook
    // already marked the payout 'failed' would fall through to a fresh PI create. Because
    // BECS settlement is T+2 (48h) and Stripe's idempotency-key cache is only 24h, Stripe
    // would issue a SECOND PaymentIntent — charging the brand twice.
    execJob_seedPayout('p1', ['status' => $status]);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldNotReceive('processPayoutBatch');

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service);
})->with(['completed', 'failed', 'cancelled']);

it('handle() calls processPayoutBatch for a pending payout', function () {
    $payout = execJob_seedPayout('p1', ['status' => 'pending']);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('processPayoutBatch')
        ->once()
        ->with(Mockery::on(fn ($p) => $p->id === 'p1'));

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service);
});

it('handle() calls processPayoutBatch for a processing payout (idempotent resume)', function () {
    execJob_seedPayout('p1', ['status' => 'processing']);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('processPayoutBatch')->once();

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service);
});

it('handle() returns cleanly when processPayoutBatch returns null (processing — awaiting webhook)', function () {
    execJob_seedPayout('p1', ['status' => 'processing']);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('processPayoutBatch')
        ->once()
        ->andReturn(null);

    Log::spy();

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service);

    expect(CommissionPayout::find('p1')->status)->toBe('processing');

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($msg) => str_contains($msg, 'parked at processing'))
        ->once();
});

it('handle() logs cancelled-by-revalidation when processPayoutBatch cancels the payout mid-flow', function () {
    // After the BECS race fix, a payout that's already 'cancelled' at handle() entry
    // returns immediately — processPayoutBatch is never called. The cancelled-by-
    // revalidation log path only fires when processPayoutBatch ITSELF cancels the
    // payout during execution (revalidatePayoutOrders detecting all orders refunded).
    // We simulate that by seeding 'pending' and having the service mock mutate the
    // status to 'cancelled' before returning null.
    execJob_seedPayout('p1', ['status' => 'pending']);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('processPayoutBatch')
        ->once()
        ->andReturnUsing(function ($payout) {
            DB::connection('pgsql')
                ->table('commerce.commission_payouts')
                ->where('id', $payout->id)
                ->update(['status' => 'cancelled']);

            return null;
        });

    Log::spy();

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($msg) => str_contains($msg, 'cancelled by order revalidation'))
        ->once();
    Log::shouldNotHaveReceived('info', [
        Mockery::on(fn ($msg) => str_contains($msg, 'parked at processing')),
        Mockery::any(),
    ]);
});

// ─── failed() ────────────────────────────────────────────────────────────────

it('failed() transitions a processing payout to failed with job_exhausted code', function () {
    execJob_seedPayout('p1', ['status' => 'processing']);

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->failed(new \RuntimeException('Stripe network timeout'));

    $payout = CommissionPayout::find('p1');
    expect($payout->status)->toBe('failed');
    expect($payout->failure_code)->toBe('job_exhausted');
    expect($payout->failure_reason)->toContain('Stripe network timeout');
});

it('failed() does not overwrite a completed payout', function () {
    execJob_seedPayout('p1', ['status' => 'completed']);

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->failed(new \RuntimeException('Late callback'));

    $payout = CommissionPayout::find('p1');
    expect($payout->status)->toBe('completed');
    expect($payout->failure_code)->toBeNull();
});

it('failed() does not overwrite a payout already marked failed', function () {
    execJob_seedPayout('p1', [
        'status' => 'failed',
        'failure_code' => 'card_declined',
    ]);

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->failed(new \RuntimeException('Duplicate callback'));

    $payout = CommissionPayout::find('p1');
    expect($payout->failure_code)->toBe('card_declined');
});

it('failed() is a no-op when payout no longer exists', function () {
    $job = new ExecuteCommissionPayoutJob('missing-id');

    $job->failed(new \RuntimeException('Ghost job'));

    expect(true)->toBeTrue();
});

it('failed() releases linked orders back to the sweep pool (payout_id → NULL)', function () {
    execJob_seedPayout('p1', ['status' => 'processing']);

    // Seed two linked orders so we can verify both are released.
    $now = now()->toDateTimeString();
    $orderIds = [
        \Illuminate\Support\Str::uuid()->toString(),
        \Illuminate\Support\Str::uuid()->toString(),
    ];
    foreach ($orderIds as $orderId) {
        \Illuminate\Support\Facades\DB::connection('pgsql')->table('commerce.orders')->insert([
            'id' => $orderId,
            'shopify_order_id' => 'shop_'.$orderId,
            'shopify_shop_domain' => 'test-'.$orderId.'.myshopify.com',
            'brand_professional_id' => \Illuminate\Support\Str::uuid()->toString(),
            'affiliate_professional_id' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => 'approved',
            'gross_cents' => 10000,
            'commission_cents' => 1000,
            'refund_cents' => 0,
            'net_cents' => 10000,
            'commission_rate' => 10,
            'rate_source' => 'brand_default',
            'currency_code' => 'AUD',
            'payout_id' => 'p1',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->failed(new \RuntimeException('Stripe network timeout'));

    // Both orders should have payout_id cleared so the next sweep can re-batch them.
    foreach ($orderIds as $orderId) {
        $row = \Illuminate\Support\Facades\DB::connection('pgsql')->table('commerce.orders')->where('id', $orderId)->first();
        expect($row->payout_id)->toBeNull();
    }
});

// ─── retryPayout() v2 behaviour ─────────────────────────────────────────────

it('retryPayout() resets to pending and increments retry_count', function () {
    execJob_seedPayout('p1', [
        'status' => 'failed',
        'failure_code' => 'card_declined',
        'retry_count' => 2,
    ]);

    $payout = CommissionPayout::find('p1');

    $service = new class extends \App\Services\Stripe\CommissionPayoutService
    {
        public ?string $capturedStatus = null;

        public function __construct() {}

        public function processPayoutBatch(CommissionPayout $payout): ?bool
        {
            $this->capturedStatus = $payout->status;

            return true;
        }
    };

    $result = $service->retryPayout($payout);

    expect($service->capturedStatus)->toBe('pending');
    expect($result)->toBeTrue();
    expect(CommissionPayout::find('p1')->retry_count)->toBe(3);
});

it('retryPayout() returns false for non-failed/cancelled payouts', function () {
    execJob_seedPayout('p1', ['status' => 'pending']);

    $payout = CommissionPayout::find('p1');

    $service = new class extends \App\Services\Stripe\CommissionPayoutService
    {
        public function __construct() {}

        public function processPayoutBatch(CommissionPayout $payout): ?bool
        {
            throw new \LogicException('Should not be called');
        }
    };

    expect($service->retryPayout($payout))->toBeFalse();
});

// ─── Uniqueness / config ────────────────────────────────────────────────────

it('uniqueId() returns the payout ID', function () {
    $job = new ExecuteCommissionPayoutJob('payout-abc');

    expect($job->uniqueId())->toBe('payout-abc');
});

it('backoff() returns the configured intervals', function () {
    $job = new ExecuteCommissionPayoutJob('p1');

    expect($job->backoff())->toBe([60, 120, 300, 600]);
});
