<?php

use App\Jobs\Analytics\RebuildCommerceDailyAggregatesJob;
use App\Jobs\Stripe\ExecuteCommissionPayoutJob;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

// Tests for CommissionPayoutService::processPayoutBatch and retryPayout, plus
// ExecuteCommissionPayoutJob::failed(). These cover the three gaps fixed in V2:
//   Gap 1 — idempotent resume (no wallet double-debit, skip completed steps)
//   Gap 2 — auto-refund on transfer failure
//   Gap 3 — job-level observability (failed() hook transitions payout status)

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow(null);
    date_default_timezone_set('UTC');
});

beforeEach(function () {
    Bus::fake();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT DEFAULT \'not_connected\'',
        'stripe_customer_id TEXT',
        'stripe_payment_method_id TEXT',
        'stripe_manual_balance_cents INTEGER DEFAULT 0',
        'stripe_manual_balance_currency TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
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
        void_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

// --- seed helpers (prefixed with "payoutSvc_" to avoid global name collisions) ---

function payoutSvc_seedBrand(string $id, array $overrides = []): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => "Brand {$id}",
        'professional_type' => 'brand',
        'status' => 'active',
        'stripe_connect_status' => 'active',
        'stripe_customer_id' => 'cus_test',
        'stripe_payment_method_id' => 'pm_test',
        'stripe_manual_balance_cents' => 0,
        'stripe_manual_balance_currency' => 'AUD',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function payoutSvc_seedAffiliate(string $id, array $overrides = []): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "affiliate-{$id}",
        'handle_lc' => "affiliate-{$id}",
        'display_name' => "Affiliate {$id}",
        'professional_type' => 'influencer',
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_test',
        'stripe_connect_status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));
}

function payoutSvc_seedPayout(string $id, array $overrides = []): CommissionPayout
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

/**
 * Build a Mockery StripeClient mock wired with per-service sub-mocks.
 * StripeClient::__get delegates to getService(), so we mock getService() —
 * that is the actual method Mockery intercepts when $stripe->paymentIntents is accessed.
 */
function payoutSvc_makeStripe(array $services = []): StripeClient
{
    $piMock = $services['pi'] ?? Mockery::mock();
    $transferMock = $services['transfer'] ?? Mockery::mock();
    $refundMock = $services['refund'] ?? Mockery::mock();

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->shouldReceive('getService')->with('paymentIntents')->andReturn($piMock);
    $stripe->shouldReceive('getService')->with('transfers')->andReturn($transferMock);
    $stripe->shouldReceive('getService')->with('refunds')->andReturn($refundMock);

    return $stripe;
}

// ============================================================
// Guard conditions
// ============================================================

it('returns true immediately when payout is already completed', function () {
    payoutSvc_seedBrand('brand-1');
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['status' => 'completed']);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->shouldNotReceive('__get');

    $service = new CommissionPayoutService($stripe);
    $result = $service->processPayoutBatch($payout);

    expect($result)->toBeTrue();
});

it('marks affiliate_not_connected when affiliate has no active Stripe account', function () {
    payoutSvc_seedBrand('brand-1');
    payoutSvc_seedAffiliate('aff-1', [
        'stripe_connect_account_id' => null,
        'stripe_connect_status' => 'not_connected',
    ]);
    $payout = payoutSvc_seedPayout('p1');

    $service = new CommissionPayoutService(Mockery::mock(StripeClient::class));
    $result = $service->processPayoutBatch($payout);

    expect($result)->toBeNull();
    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('pending_funds');
    expect($fresh->failure_code)->toBe('affiliate_not_connected');
});

it('fails with brand_missing when brand professional does not exist', function () {
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['brand_professional_id' => 'nonexistent-brand']);

    $service = new CommissionPayoutService(Mockery::mock(StripeClient::class));
    $result = $service->processPayoutBatch($payout);

    expect($result)->toBeFalse();
    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->failure_code)->toBe('brand_missing');
});

it('marks brand_payment_method_missing when brand has no card on file', function () {
    payoutSvc_seedBrand('brand-1', ['stripe_payment_method_id' => null]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1');

    $service = new CommissionPayoutService(Mockery::mock(StripeClient::class));
    $result = $service->processPayoutBatch($payout);

    expect($result)->toBeNull();
    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('pending_funds');
    expect($fresh->failure_code)->toBe('brand_payment_method_missing');
});

// ============================================================
// Happy paths
// ============================================================

it('completes a card-only payout when brand wallet balance is zero', function () {
    payoutSvc_seedBrand('brand-1', ['stripe_manual_balance_cents' => 0]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['gross_commission_cents' => 10000, 'net_payout_cents' => 9700]);

    $piMock = Mockery::mock();
    $piMock->shouldReceive('create')->once()->andReturn((object) [
        'id' => 'pi_test',
        'status' => 'succeeded',
        'latest_charge' => 'ch_test',
    ]);

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')->once()->andReturn((object) ['id' => 'tr_test']);

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock]));
    $result = $service->processPayoutBatch($payout);

    $payout->refresh();
    expect($result)->toBeTrue();
    expect($payout->status)->toBe('completed');
    expect($payout->stripe_transfer_id)->toBe('tr_test');
    expect($payout->wallet_debit_cents)->toBe(0);
    expect($payout->charge_cents)->toBe(10000);
    expect($payout->funding_source)->toBe('card');
    Bus::assertDispatched(RebuildCommerceDailyAggregatesJob::class);
});

it('completes a wallet-only payout without creating a PaymentIntent', function () {
    payoutSvc_seedBrand('brand-1', [
        'stripe_manual_balance_cents' => 20000,
        'stripe_manual_balance_currency' => 'AUD',
    ]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['gross_commission_cents' => 10000, 'net_payout_cents' => 9700]);

    $piMock = Mockery::mock();
    $piMock->shouldNotReceive('create');

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')->once()->andReturn((object) ['id' => 'tr_wallet']);

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock]));
    $result = $service->processPayoutBatch($payout);

    $payout->refresh();
    expect($result)->toBeTrue();
    expect($payout->status)->toBe('completed');
    expect($payout->wallet_debit_cents)->toBe(10000);
    expect($payout->charge_cents)->toBe(0);
    expect($payout->funding_source)->toBe('wallet');
});

it('completes a wallet-and-card payout when wallet partially covers the amount', function () {
    payoutSvc_seedBrand('brand-1', [
        'stripe_manual_balance_cents' => 3000,
        'stripe_manual_balance_currency' => 'AUD',
    ]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['gross_commission_cents' => 10000, 'net_payout_cents' => 9700]);

    $piMock = Mockery::mock();
    $piMock->shouldReceive('create')
        ->once()
        ->with(Mockery::on(fn ($p) => $p['amount'] === 7000), Mockery::any())
        ->andReturn((object) ['id' => 'pi_test', 'status' => 'succeeded', 'latest_charge' => 'ch_test']);

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')->once()->andReturn((object) ['id' => 'tr_test']);

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock]));
    $result = $service->processPayoutBatch($payout);

    $payout->refresh();
    expect($result)->toBeTrue();
    expect($payout->wallet_debit_cents)->toBe(3000);
    expect($payout->charge_cents)->toBe(7000);
    expect($payout->funding_source)->toBe('wallet_and_card');
});

// ============================================================
// Gap 1 — Idempotent resume (no double-debit / no double-charge)
// ============================================================

it('does not re-debit the wallet when resuming from collecting status', function () {
    // Brand has zero wallet balance; a previous run already debited 5000 and committed
    // the payout to 'collecting'. On resume the service must read wallet_debit_cents
    // from the DB rather than calling debitBrandManualBalancePartial again.
    payoutSvc_seedBrand('brand-1', ['stripe_manual_balance_cents' => 0]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', [
        'status' => 'collecting',
        'wallet_debit_cents' => 5000,
        'charge_cents' => 5000,
        'funding_source' => 'wallet_and_card',
        'gross_commission_cents' => 10000,
        'net_payout_cents' => 9700,
    ]);

    $piMock = Mockery::mock();
    $piMock->shouldReceive('create')->once()->andReturn((object) [
        'id' => 'pi_test', 'status' => 'succeeded', 'latest_charge' => 'ch_test',
    ]);

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')->once()->andReturn((object) ['id' => 'tr_test']);

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock]));
    $result = $service->processPayoutBatch($payout);

    expect($result)->toBeTrue();
    // Wallet stays at 0 — the resume path read wallet_debit_cents from the record
    $brand = Professional::find('brand-1');
    expect((int) $brand->stripe_manual_balance_cents)->toBe(0);
});

it('skips PaymentIntent creation and retrieves the existing one when resuming from collected status', function () {
    payoutSvc_seedBrand('brand-1');
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', [
        'status' => 'collected',
        'stripe_payment_intent_id' => 'pi_existing',
        'wallet_debit_cents' => 0,
        'charge_cents' => 10000,
        'gross_commission_cents' => 10000,
        'net_payout_cents' => 9700,
    ]);

    $piMock = Mockery::mock();
    $piMock->shouldNotReceive('create');
    $piMock->shouldReceive('retrieve')->with('pi_existing')->once()->andReturn((object) [
        'id' => 'pi_existing', 'status' => 'succeeded', 'latest_charge' => 'ch_existing',
    ]);

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')
        ->once()
        ->with(Mockery::on(fn ($p) => ($p['source_transaction'] ?? null) === 'ch_existing'), Mockery::any())
        ->andReturn((object) ['id' => 'tr_test']);

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock]));
    $result = $service->processPayoutBatch($payout);

    expect($result)->toBeTrue();
    expect($payout->fresh()->status)->toBe('completed');
});

it('skips wallet debit and card charge when resuming from transferring status', function () {
    payoutSvc_seedBrand('brand-1', ['stripe_manual_balance_cents' => 0]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', [
        'status' => 'transferring',
        'wallet_debit_cents' => 0,
        'charge_cents' => 10000,
        'gross_commission_cents' => 10000,
        'net_payout_cents' => 9700,
    ]);

    $piMock = Mockery::mock();
    $piMock->shouldNotReceive('create');
    $piMock->shouldNotReceive('retrieve');

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')->once()->andReturn((object) ['id' => 'tr_test']);

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock]));
    $result = $service->processPayoutBatch($payout);

    expect($result)->toBeTrue();
    expect($payout->fresh()->stripe_transfer_id)->toBe('tr_test');
});

it('skips transfer creation and completes when stripe_transfer_id was already recorded', function () {
    // Simulates a crash between saving stripe_transfer_id and saving status=completed.
    // On resume the service must not create a second transfer.
    payoutSvc_seedBrand('brand-1', ['stripe_manual_balance_cents' => 0]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', [
        'status' => 'transferring',
        'stripe_transfer_id' => 'tr_already',
        'wallet_debit_cents' => 0,
        'charge_cents' => 10000,
        'gross_commission_cents' => 10000,
        'net_payout_cents' => 9700,
    ]);

    $transferMock = Mockery::mock();
    $transferMock->shouldNotReceive('create');

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['transfer' => $transferMock]));
    $result = $service->processPayoutBatch($payout);

    $fresh = $payout->fresh();
    expect($result)->toBeTrue();
    expect($fresh->status)->toBe('completed');
    expect($fresh->stripe_transfer_id)->toBe('tr_already');
});

// ============================================================
// Gap 2 — Auto-refund on transfer failure
// ============================================================

it('credits wallet and auto-refunds card when transfer fails and refund succeeds', function () {
    payoutSvc_seedBrand('brand-1', ['stripe_manual_balance_cents' => 0]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['gross_commission_cents' => 10000, 'net_payout_cents' => 9700]);

    $piMock = Mockery::mock();
    $piMock->shouldReceive('create')->andReturn((object) [
        'id' => 'pi_test', 'status' => 'succeeded', 'latest_charge' => 'ch_test',
    ]);

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')->andThrow(
        new InvalidRequestException('Transfer destination not found')
    );

    $refundMock = Mockery::mock();
    $refundMock->shouldReceive('create')->once()
        ->with(['payment_intent' => 'pi_test'], ['idempotency_key' => 'rf_p1_pi_test'])
        ->andReturn((object) ['id' => 're_test']);

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock, 'refund' => $refundMock]));
    $result = $service->processPayoutBatch($payout);

    $payout->refresh();
    expect($result)->toBeFalse();
    expect($payout->status)->toBe('failed');
    expect($payout->failure_code)->toBe('transfer_failed_refunded');
    // PI ID cleared so an admin retry generates a fresh idempotency key
    expect($payout->stripe_payment_intent_id)->toBeNull();
});

it('marks transfer_failed_refund_needed when transfer fails and auto-refund also fails', function () {
    payoutSvc_seedBrand('brand-1');
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['gross_commission_cents' => 10000, 'net_payout_cents' => 9700]);

    $piMock = Mockery::mock();
    $piMock->shouldReceive('create')->andReturn((object) [
        'id' => 'pi_test', 'status' => 'succeeded', 'latest_charge' => 'ch_test',
    ]);

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')->andThrow(
        new InvalidRequestException('Transfer failed')
    );

    $refundMock = Mockery::mock();
    $refundMock->shouldReceive('create')->andThrow(new \RuntimeException('Refund API down'));

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock, 'refund' => $refundMock]));
    $result = $service->processPayoutBatch($payout);

    $payout->refresh();
    expect($result)->toBeFalse();
    expect($payout->status)->toBe('failed');
    expect($payout->failure_code)->toBe('transfer_failed_refund_needed');
    // PI ID is NOT cleared — manual refund still requires the reference
    expect($payout->stripe_payment_intent_id)->toBe('pi_test');
});

// ============================================================
// Transient error re-throws (Horizon retry contract)
// ============================================================

it('re-throws ApiConnectionException from paymentIntents create so Horizon retries', function () {
    payoutSvc_seedBrand('brand-1', ['stripe_manual_balance_cents' => 0]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1');

    $piMock = Mockery::mock();
    $piMock->shouldReceive('create')->andThrow(new ApiConnectionException('Network error'));

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock]));

    expect(fn () => $service->processPayoutBatch($payout))
        ->toThrow(ApiConnectionException::class);

    // Wallet debit + status update committed atomically before the exception
    expect($payout->fresh()->status)->toBe('collecting');
});

it('re-throws ApiConnectionException from transfers create so Horizon retries without refunding', function () {
    payoutSvc_seedBrand('brand-1', ['stripe_manual_balance_cents' => 0]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1');

    $piMock = Mockery::mock();
    $piMock->shouldReceive('create')->andReturn((object) [
        'id' => 'pi_test', 'status' => 'succeeded', 'latest_charge' => 'ch_test',
    ]);

    $refundMock = Mockery::mock();
    $refundMock->shouldNotReceive('create');

    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')->andThrow(new ApiConnectionException('Timeout'));

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock, 'refund' => $refundMock]));

    expect(fn () => $service->processPayoutBatch($payout))
        ->toThrow(ApiConnectionException::class);

    // Payout status advances to 'transferring' before the exception so Horizon
    // resumes from this step rather than re-charging on the next attempt.
    expect($payout->fresh()->status)->toBe('transferring');
});

// ============================================================
// retryPayout
// ============================================================

it('blocks retryPayout when failure_code is transfer_failed_refund_needed', function () {
    payoutSvc_seedBrand('brand-1');
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', [
        'status' => 'failed',
        'failure_code' => 'transfer_failed_refund_needed',
    ]);

    $service = new CommissionPayoutService(Mockery::mock(StripeClient::class));
    $result = $service->retryPayout($payout);

    expect($result)->toBeFalse();
    // Payout not touched
    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('failed');
    expect($fresh->failure_code)->toBe('transfer_failed_refund_needed');
});

it('blocks retryPayout when payout status is neither failed nor pending', function () {
    payoutSvc_seedBrand('brand-1');
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['status' => 'completed']);

    $service = new CommissionPayoutService(Mockery::mock(StripeClient::class));
    $result = $service->retryPayout($payout);

    expect($result)->toBeFalse();
    expect($payout->fresh()->status)->toBe('completed');
});

it('increments retry_count on admin retry so a fresh PaymentIntent idempotency key is used', function () {
    payoutSvc_seedBrand('brand-1', ['stripe_manual_balance_cents' => 0]);
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', [
        'status' => 'failed',
        'failure_code' => 'charge_failed',
        'retry_count' => 2,
    ]);

    $piMock = Mockery::mock();
    $piMock->shouldReceive('create')->andReturn((object) [
        'id' => 'pi_r3', 'status' => 'succeeded', 'latest_charge' => 'ch_r3',
    ]);
    $transferMock = Mockery::mock();
    $transferMock->shouldReceive('create')->andReturn((object) ['id' => 'tr_r3']);

    $service = new CommissionPayoutService(payoutSvc_makeStripe(['pi' => $piMock, 'transfer' => $transferMock]));
    $service->retryPayout($payout);

    expect($payout->fresh()->retry_count)->toBe(3);
});

// ============================================================
// ExecuteCommissionPayoutJob::failed() hook
// ============================================================

it('transitions payout to failed when the job exhausts all Horizon retries', function () {
    payoutSvc_seedBrand('brand-1');
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['status' => 'collecting']);

    $job = new ExecuteCommissionPayoutJob($payout->id);
    $job->failed(new \RuntimeException('Stripe outage after 5 attempts'));

    $payout->refresh();
    expect($payout->status)->toBe('failed');
    expect($payout->failure_code)->toBe('job_exhausted');
    expect($payout->failure_reason)->toContain('Stripe outage after 5 attempts');
});

it('does not overwrite already-completed status in the failed hook', function () {
    payoutSvc_seedBrand('brand-1');
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', ['status' => 'completed']);

    $job = new ExecuteCommissionPayoutJob($payout->id);
    $job->failed(new \RuntimeException('Late error'));

    expect($payout->fresh()->status)->toBe('completed');
});

it('does not overwrite already-failed status in the failed hook', function () {
    payoutSvc_seedBrand('brand-1');
    payoutSvc_seedAffiliate('aff-1');
    $payout = payoutSvc_seedPayout('p1', [
        'status' => 'failed',
        'failure_code' => 'transfer_failed_refund_needed',
    ]);

    $job = new ExecuteCommissionPayoutJob($payout->id);
    $job->failed(new \RuntimeException('Second failure'));

    $fresh = $payout->fresh();
    expect($fresh->status)->toBe('failed');
    // Original failure_code preserved
    expect($fresh->failure_code)->toBe('transfer_failed_refund_needed');
});

// ============================================================
// #V5-025 — UTC cutoff for payout hold window
// ============================================================

it('uses UTC for payout cutoff so an app-timezone offset does not shorten the hold window', function () {
    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
        id TEXT PRIMARY KEY, professional_id TEXT, payout_hold_days INTEGER,
        default_commission_rate REAL, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_ledger_entries (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT, affiliate_professional_id TEXT,
        entry_type TEXT, status TEXT, amount_cents INTEGER, currency_code TEXT,
        commission_rate REAL, rate_source TEXT,
        idempotency_key TEXT UNIQUE, calculation_metadata TEXT, payout_id TEXT,
        occurred_at TEXT NOT NULL, created_at TEXT, updated_at TEXT
    )');
    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY, payout_id TEXT, commission_ledger_entry_id TEXT,
        amount_cents INTEGER, created_at TEXT, updated_at TEXT
    )');

    // Carbon::now() converts testNow to date_default_timezone_get(), so we must set
    // the PHP default TZ BEFORE setting the mock. With Auckland (UTC+12) as the default,
    // now() returns '2026-05-02 12:00:00 +1200' and the two cutoff strings diverge:
    //   now()->subDays(7)        → '2026-04-25 12:00:00' (Auckland local, no TZ in string)
    //   now()->utc()->subDays(7) → '2026-04-25 00:00:00' (UTC)
    date_default_timezone_set('Pacific/Auckland');
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-05-02 00:00:00', 'UTC'));

    payoutSvc_seedBrand('brand-tz');
    payoutSvc_seedAffiliate('aff-tz');

    $conn->table('brand.brand_store_settings')->insert([
        'id' => 'bss-tz', 'professional_id' => 'brand-tz', 'payout_hold_days' => 7,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    // Entry from 6 days + 18 hours ago (UTC). Still within the 7-day hold window.
    // App-TZ cutoff '2026-04-25 12:00:00' incorrectly includes it (06:00 ≤ 12:00).
    // UTC cutoff    '2026-04-25 00:00:00' correctly excludes it  (06:00 > 00:00).
    $conn->table('commerce.commission_ledger_entries')->insert([
        'id' => 'entry-tz',
        'brand_professional_id' => 'brand-tz',
        'affiliate_professional_id' => 'aff-tz',
        'entry_type' => 'accrual',
        'status' => 'approved',
        'amount_cents' => 10000,
        'currency_code' => 'AUD',
        'commission_rate' => 10.0,
        'rate_source' => 'brand_default',
        'idempotency_key' => 'entry-tz-key',
        'calculation_metadata' => '{}',
        'occurred_at' => '2026-04-25 06:00:00',
        'created_at' => '2026-04-25 06:00:00',
        'updated_at' => '2026-04-25 06:00:00',
    ]);

    $service = new CommissionPayoutService(Mockery::mock(StripeClient::class));
    $stats = $service->processEligiblePayouts();

    expect($stats['batches_created'])->toBe(0);
});
