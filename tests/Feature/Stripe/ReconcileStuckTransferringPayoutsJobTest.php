<?php

use App\Jobs\Stripe\ReconcileStuckTransferringPayoutsJob;
use App\Models\Retail\CommissionPayout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Tests for the daily reconciler that finds `transferring` payouts stuck for
// more than 6 hours and flips them to completed or failed by fetching the
// Stripe Transfer status. Closes the gap where ExecuteCommissionPayoutJob
// marks a payout `transferring` but the Transfer.paid webhook never arrives.

beforeEach(function () {
    attachTestSchemas();

    // Full schema including lifecycle columns added in 20260510000000_.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        stripe_payment_intent_id TEXT NULL,
        stripe_transfer_id TEXT NULL,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_commission_cents INTEGER NOT NULL DEFAULT 0,
        platform_fee_cents INTEGER NOT NULL DEFAULT 0,
        net_payout_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        failure_reason TEXT NULL,
        failure_code TEXT NULL,
        failure_category TEXT NULL,
        stripe_error_code TEXT NULL,
        stripe_error_message TEXT NULL,
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT NULL,
        processed_at TEXT NULL,
        funding_source TEXT NULL,
        wallet_debit_cents INTEGER DEFAULT 0,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT NULL,
        transfer_completed_at TEXT NULL,
        next_retry_at TEXT NULL,
        last_retry_at TEXT NULL,
        funding_failure_count INTEGER DEFAULT 0,
        grace_notifications_sent TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow(null);
});

/**
 * Seed a minimal commission_payouts row and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function reconcileStuck_seedPayout(array $overrides = []): CommissionPayout
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id'                        => $id,
        'brand_professional_id'     => null,
        'affiliate_professional_id' => null,
        'stripe_transfer_id'        => null,
        'status'                    => 'pending',
        'gross_commission_cents'    => 10000,
        'platform_fee_cents'        => 300,
        'net_payout_cents'          => 9700,
        'currency_code'             => 'AUD',
        'ledger_entry_count'        => 0,
        'wallet_debit_cents'        => 0,
        'charge_cents'              => 0,
        'retry_count'               => 0,
        'needs_manual_refund'       => 0,
        'created_at'                => $now,
        'updated_at'                => $now,
    ], $overrides, ['id' => $overrides['id'] ?? $id]));

    return CommissionPayout::find($overrides['id'] ?? $id);
}

/**
 * Build the job with a pre-configured Mockery StripeClient mock.
 */
function reconcileStuck_makeJob(\Mockery\MockInterface $stripeMock): ReconcileStuckTransferringPayoutsJob
{
    return new ReconcileStuckTransferringPayoutsJob($stripeMock);
}

/**
 * Build a bare Mockery mock for the Stripe transfers sub-service.
 */
function reconcileStuck_transfersMock(): \Mockery\MockInterface
{
    return Mockery::mock();
}

it('flips a stuck transferring payout to completed when Stripe says paid', function () {
    $payout = reconcileStuck_seedPayout([
        'status'             => 'transferring',
        'stripe_transfer_id' => 'tr_stuck_1',
        'updated_at'         => now()->subHours(8)->toDateTimeString(),
    ]);

    $transfersMock = reconcileStuck_transfersMock();
    $transfersMock->shouldReceive('retrieve')
        ->once()
        ->with('tr_stuck_1')
        ->andReturn((object) [
            'id'     => 'tr_stuck_1',
            'status' => 'paid',
        ]);

    reconcileStuck_makeJob(mockStripeClient(['transfers' => $transfersMock]))->handle();

    $payout->refresh();
    expect($payout->status)->toBe('completed');
    expect($payout->transfer_completed_at)->not->toBeNull();
});

it('skips payouts that are not stuck (updated_at < 6h ago)', function () {
    $payout = reconcileStuck_seedPayout([
        'status'             => 'transferring',
        'stripe_transfer_id' => 'tr_recent',
        'updated_at'         => now()->subHour()->toDateTimeString(),
    ]);

    // transfers->retrieve must never be called for a non-stuck payout.
    $transfersMock = reconcileStuck_transfersMock();
    $transfersMock->shouldNotReceive('retrieve');

    reconcileStuck_makeJob(mockStripeClient(['transfers' => $transfersMock]))->handle();

    expect($payout->fresh()->status)->toBe('transferring');
});

it('skips payouts without a stripe_transfer_id', function () {
    $payout = reconcileStuck_seedPayout([
        'status'             => 'transferring',
        'stripe_transfer_id' => null,
        'updated_at'         => now()->subHours(8)->toDateTimeString(),
    ]);

    $transfersMock = reconcileStuck_transfersMock();
    $transfersMock->shouldNotReceive('retrieve');

    reconcileStuck_makeJob(mockStripeClient(['transfers' => $transfersMock]))->handle();

    expect($payout->fresh()->status)->toBe('transferring');
});

it('flags payouts as failed when Stripe says failed', function () {
    $payout = reconcileStuck_seedPayout([
        'status'             => 'transferring',
        'stripe_transfer_id' => 'tr_failed',
        'updated_at'         => now()->subHours(7)->toDateTimeString(),
    ]);

    $transfersMock = reconcileStuck_transfersMock();
    $transfersMock->shouldReceive('retrieve')
        ->once()
        ->andReturn((object) [
            'id'              => 'tr_failed',
            'status'          => 'failed',
            'failure_code'    => 'account_closed',
            'failure_message' => 'The destination account has been closed.',
        ]);

    reconcileStuck_makeJob(mockStripeClient(['transfers' => $transfersMock]))->handle();

    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->stripe_error_code)->toBe('account_closed');
    expect($fresh->stripe_error_message)->toBe('The destination account has been closed.');
    expect($fresh->failure_code)->toBe('transfer_failed_reconciliation');
    expect($fresh->failure_category)->toBe('affiliate_account');
});

it('skips payouts in non-transferring statuses', function () {
    $completed = reconcileStuck_seedPayout([
        'status'     => 'completed',
        'updated_at' => now()->subHours(8)->toDateTimeString(),
    ]);
    $pending = reconcileStuck_seedPayout([
        'status'     => 'pending',
        'updated_at' => now()->subHours(8)->toDateTimeString(),
    ]);

    $transfersMock = reconcileStuck_transfersMock();
    $transfersMock->shouldNotReceive('retrieve');

    reconcileStuck_makeJob(mockStripeClient(['transfers' => $transfersMock]))->handle();

    expect($completed->fresh()->status)->toBe('completed');
    expect($pending->fresh()->status)->toBe('pending');
});

it('continues processing remaining payouts when Stripe throws on one', function () {
    $bad = reconcileStuck_seedPayout([
        'id'                 => 'payout-bad',
        'status'             => 'transferring',
        'stripe_transfer_id' => 'tr_api_error',
        'updated_at'         => now()->subHours(8)->toDateTimeString(),
    ]);
    $good = reconcileStuck_seedPayout([
        'id'                 => 'payout-good',
        'status'             => 'transferring',
        'stripe_transfer_id' => 'tr_ok',
        'updated_at'         => now()->subHours(8)->toDateTimeString(),
    ]);

    $transfersMock = reconcileStuck_transfersMock();
    $transfersMock->shouldReceive('retrieve')
        ->with('tr_api_error')
        ->andThrow(new \Stripe\Exception\ApiConnectionException('network error'));
    $transfersMock->shouldReceive('retrieve')
        ->with('tr_ok')
        ->andReturn((object) ['id' => 'tr_ok', 'status' => 'paid']);

    reconcileStuck_makeJob(mockStripeClient(['transfers' => $transfersMock]))->handle();

    // The erroring payout stays transferring; the good one completes.
    expect($bad->fresh()->status)->toBe('transferring');
    expect($good->fresh()->status)->toBe('completed');
});

it('leaves a pending-status transfer untouched', function () {
    $payout = reconcileStuck_seedPayout([
        'status'             => 'transferring',
        'stripe_transfer_id' => 'tr_still_pending',
        'updated_at'         => now()->subHours(8)->toDateTimeString(),
    ]);

    $transfersMock = reconcileStuck_transfersMock();
    $transfersMock->shouldReceive('retrieve')
        ->andReturn((object) ['id' => 'tr_still_pending', 'status' => 'pending']);

    reconcileStuck_makeJob(mockStripeClient(['transfers' => $transfersMock]))->handle();

    // 'pending' from Stripe means still in-flight — no status change.
    expect($payout->fresh()->status)->toBe('transferring');
});
