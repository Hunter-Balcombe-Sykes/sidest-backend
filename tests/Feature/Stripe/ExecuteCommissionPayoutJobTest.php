<?php

use App\Jobs\Stripe\ExecuteCommissionPayoutJob;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Tests for ExecuteCommissionPayoutJob: handle(), failed(), and the retryPayout()
// double-debit fix (wallet_debit_cents > 0 resumes from 'collecting', not 'pending').

beforeEach(function () {
    setupProfessionalsTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        stripe_payment_intent_id TEXT,
        stripe_transfer_id TEXT,
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
        funding_source TEXT,
        wallet_debit_cents INTEGER DEFAULT 0,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
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
        'wallet_debit_cents' => 0,
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

it('handle() skips processing when payout is already completed', function () {
    execJob_seedPayout('p1', ['status' => 'completed']);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldNotReceive('processPayoutBatch');

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service);
});

it('handle() calls processPayoutBatch for a pending payout', function () {
    $payout = execJob_seedPayout('p1', ['status' => 'pending']);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('processPayoutBatch')
        ->once()
        ->with(Mockery::on(fn ($p) => $p->id === 'p1'));

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service);
});

it('handle() calls processPayoutBatch for a mid-flight collecting payout', function () {
    execJob_seedPayout('p1', ['status' => 'collecting', 'wallet_debit_cents' => 5000]);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('processPayoutBatch')->once();

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service);
});

it('handle() returns cleanly when processPayoutBatch returns null (transfer in-flight)', function () {
    // null return means the transfer is parked at 'transferring'; the webhook will complete it.
    // The job must not throw, must not retry, and must not alter payout state.
    execJob_seedPayout('p1', ['status' => 'transferring']);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('processPayoutBatch')
        ->once()
        ->andReturn(null);

    Log::spy();

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service); // must not throw

    // Payout status unchanged — webhook handles the final transition.
    expect(CommissionPayout::find('p1')->status)->toBe('transferring');

    // Logs the awaiting-webhook message, NOT the cancelled message.
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($msg) => str_contains($msg, 'parked at transferring'))
        ->once();
});

it('handle() logs cancelled-by-revalidation (not awaiting-webhook) when processPayoutBatch returns null on a cancelled payout', function () {
    // Both the in-flight transfer case and the revalidation-cancelled case return null from
    // processPayoutBatch. The handler must distinguish them so a cancelled payout is not
    // logged as "stuck in transferring" and chased by ReconcileStuckTransferringPayoutsJob.
    execJob_seedPayout('p1', ['status' => 'cancelled']);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('processPayoutBatch')
        ->once()
        ->andReturn(null);

    Log::spy();

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->handle($service);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($msg) => str_contains($msg, 'cancelled by order revalidation'))
        ->once();
    Log::shouldNotHaveReceived('info', [
        Mockery::on(fn ($msg) => str_contains($msg, 'parked at transferring')),
        Mockery::any(),
    ]);
});

// ─── failed() ────────────────────────────────────────────────────────────────

it('failed() transitions a collecting payout to failed with job_exhausted code', function () {
    execJob_seedPayout('p1', ['status' => 'collecting']);

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
        'failure_code' => 'transfer_failed',
    ]);

    $job = new ExecuteCommissionPayoutJob('p1');
    $job->failed(new \RuntimeException('Duplicate callback'));

    $payout = CommissionPayout::find('p1');
    // Code should remain the original; failed() guards against overwriting
    expect($payout->failure_code)->toBe('transfer_failed');
});

it('failed() is a no-op when payout no longer exists', function () {
    $job = new ExecuteCommissionPayoutJob('missing-id');

    // Should not throw
    $job->failed(new \RuntimeException('Ghost job'));

    expect(true)->toBeTrue();
});

// ─── retryPayout() double-debit fix ──────────────────────────────────────────

it('retryPayout() resets to collecting when wallet was previously debited, preventing double-debit', function () {
    // Payout exhausted Horizon retries while in 'transferring' status (PI succeeded, wallet debited,
    // transfer timed out). failed() leaves wallet_debit_cents intact for this case so retryPayout()
    // must resume from 'collecting' to skip re-debiting against the already-reduced balance.
    execJob_seedPayout('p1', [
        'status' => 'failed',
        'failure_code' => 'job_exhausted',
        'wallet_debit_cents' => 4000,
        'charge_cents' => 6000,
    ]);

    $payout = CommissionPayout::find('p1');

    // Subclass so retryPayout() runs normally but processPayoutBatch is stubbed.
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

    // processPayoutBatch must have seen 'collecting', not 'pending'
    expect($service->capturedStatus)->toBe('collecting');
    expect($result)->toBeTrue();
});

it('retryPayout() resets to pending when wallet was not previously debited', function () {
    execJob_seedPayout('p1', [
        'status' => 'failed',
        'failure_code' => 'job_exhausted',
        'wallet_debit_cents' => 0,
        'charge_cents' => 0,
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

    $service->retryPayout($payout);

    expect($service->capturedStatus)->toBe('pending');
});

it('retryPayout() increments retry_count on each call', function () {
    execJob_seedPayout('p1', [
        'status' => 'failed',
        'failure_code' => 'job_exhausted',
        'retry_count' => 2,
    ]);

    $payout = CommissionPayout::find('p1');

    $service = new class extends \App\Services\Stripe\CommissionPayoutService
    {
        public function __construct() {}

        public function processPayoutBatch(CommissionPayout $payout): ?bool
        {
            return true;
        }
    };

    $service->retryPayout($payout);

    expect(CommissionPayout::find('p1')->retry_count)->toBe(3);
});

it('retryPayout() returns false and does not retry transfer_failed_refund_needed payouts', function () {
    execJob_seedPayout('p1', [
        'status' => 'failed',
        'failure_code' => 'transfer_failed_refund_needed',
    ]);

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
