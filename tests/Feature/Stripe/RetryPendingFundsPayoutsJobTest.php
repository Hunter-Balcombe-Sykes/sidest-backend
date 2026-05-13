<?php

use App\Jobs\Stripe\RetryPendingFundsPayoutsJob;
use App\Models\Retail\CommissionPayout;
use App\Notifications\Brand\BrandPayoutFundingFailedNotification;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    attachTestSchemas();

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
        funding_failure_count INTEGER NOT NULL DEFAULT 0,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        handle TEXT UNIQUE,
        handle_lc TEXT,
        display_name TEXT,
        professional_type TEXT,
        stripe_customer_id TEXT,
        stripe_payment_method_id TEXT,
        stripe_manual_balance_cents INTEGER DEFAULT 0,
        stripe_manual_balance_currency TEXT DEFAULT \'AUD\',
        notifications_enabled INTEGER DEFAULT 1,
        primary_email TEXT,
        email TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

});

function retryJob_seedBrand(string $id, array $overrides = []): void
{
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => "Brand {$id}",
        'professional_type' => 'brand',
        'primary_email' => "brand-{$id}@test.test",
        'stripe_manual_balance_cents' => 0,
        'stripe_manual_balance_currency' => 'AUD',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));
}

function retryJob_seedPayout(string $id, array $overrides = []): CommissionPayout
{
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => 'brand-1',
        'affiliate_professional_id' => 'aff-1',
        'status' => 'pending_funds',
        'gross_commission_cents' => 10000,
        'platform_fee_cents' => 300,
        'net_payout_cents' => 9700,
        'currency_code' => 'AUD',
        'funding_failure_count' => 1,
        'next_retry_at' => now()->subHour()->toDateTimeString(),
        'wallet_debit_cents' => 0,
        'retry_count' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return CommissionPayout::find($id);
}

// ─── Eligibility ────────────────────────────────────────────────────────────

it('picks up pending_funds payouts where next_retry_at <= now', function () {
    Notification::fake();
    retryJob_seedBrand('brand-1');

    $due = retryJob_seedPayout('due-1', [
        'status' => 'pending_funds',
        'next_retry_at' => now()->subHour()->toDateTimeString(),
        'funding_failure_count' => 1,
    ]);
    $notDue = retryJob_seedPayout('not-due-1', [
        'status' => 'pending_funds',
        'next_retry_at' => now()->addDay()->toDateTimeString(),
        'funding_failure_count' => 1,
    ]);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('retryPayout')
        ->once()
        ->with(Mockery::on(fn ($p) => $p->id === 'due-1'));
    // not-due must not be touched
    $service->shouldNotReceive('retryPayout', Mockery::on(fn ($p) => $p->id === 'not-due-1'));

    (new RetryPendingFundsPayoutsJob)->handle($service);
});

it('skips payouts that are not in pending_funds status', function () {
    retryJob_seedBrand('brand-1');

    retryJob_seedPayout('p1', [
        'status' => 'failed', // already terminal
        'next_retry_at' => now()->subHour()->toDateTimeString(),
    ]);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldNotReceive('retryPayout');

    (new RetryPendingFundsPayoutsJob)->handle($service);
});

// ─── retryPayout delegation ─────────────────────────────────────────────────

it('calls retryPayout (not processPayoutBatch) to ensure PI idempotency key rotates', function () {
    Notification::fake();
    retryJob_seedBrand('brand-1');

    retryJob_seedPayout('p1', [
        'funding_failure_count' => 1,
        'retry_count' => 0,
    ]);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldReceive('retryPayout')
        ->once()
        ->with(Mockery::on(fn ($p) => $p->id === 'p1'))
        ->andReturn(true);

    (new RetryPendingFundsPayoutsJob)->handle($service);
});

// ─── Terminal failure ────────────────────────────────────────────────────────

it('marks payout as terminally failed after MAX_ATTEMPTS', function () {
    Notification::fake();
    retryJob_seedBrand('brand-1');

    $payout = retryJob_seedPayout('p1', [
        'funding_failure_count' => RetryPendingFundsPayoutsJob::MAX_ATTEMPTS,
        'next_retry_at' => now()->subHour()->toDateTimeString(),
    ]);

    $service = Mockery::mock(CommissionPayoutService::class);
    // Should NOT call retryPayout — we're marking terminal directly.
    $service->shouldNotReceive('retryPayout');

    (new RetryPendingFundsPayoutsJob)->handle($service);

    $payout->refresh();
    expect($payout->status)->toBe('failed');
    expect($payout->failure_category)->toBe('brand_funding');
    expect($payout->failure_code)->toBe('brand_funding_exhausted');
});

it('sends terminal BrandPayoutFundingFailedNotification after exhausting attempts', function () {
    Notification::fake();
    retryJob_seedBrand('brand-1');

    retryJob_seedPayout('p1', [
        'funding_failure_count' => RetryPendingFundsPayoutsJob::MAX_ATTEMPTS,
        'next_retry_at' => now()->subHour()->toDateTimeString(),
        'wallet_debit_cents' => 0,
    ]);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldNotReceive('retryPayout');

    (new RetryPendingFundsPayoutsJob)->handle($service);

    Notification::assertSentTo(
        \App\Models\Core\Professional\Professional::find('brand-1'),
        BrandPayoutFundingFailedNotification::class,
        fn ($n) => $n->isTerminal === true
    );
});

it('does not re-process a payout already in failed status (concurrent guard)', function () {
    Notification::fake();
    retryJob_seedBrand('brand-1');

    // Payout already failed (concurrent job beat us).
    $payout = retryJob_seedPayout('p1', [
        'status' => 'failed',
        'funding_failure_count' => RetryPendingFundsPayoutsJob::MAX_ATTEMPTS,
        'next_retry_at' => now()->subHour()->toDateTimeString(),
    ]);

    $service = Mockery::mock(CommissionPayoutService::class);
    $service->shouldNotReceive('retryPayout');

    (new RetryPendingFundsPayoutsJob)->handle($service);

    // Notifications should NOT fire on a guard-skipped iteration.
    Notification::assertNothingSent();
});
