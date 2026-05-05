<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Http\Requests\Api\Professional\Stripe\PayoutsRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Support\Facades\DB;

// Happy-path coverage for StripeConnectController::payouts() — the
// GET /stripe/payouts endpoint that powers the affiliate earnings page and
// the brand payout-history view. This is a real-money code path with no
// prior test coverage; these tests catch the most likely regression shapes
// (broken role filter, broken eager loading, broken response envelope).
//
// We instantiate the controller directly with a mocked CommissionPayoutService
// so getPayoutSummary() doesn't run its real query. The Stripe service is
// mocked as a blank stub — payouts() never touches it, but the constructor
// still needs something that type-checks.

beforeEach(function () {
    setupProfessionalsTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL,
        stripe_payment_intent_id TEXT,
        stripe_transfer_id TEXT,
        status TEXT,
        gross_commission_cents INTEGER,
        platform_fee_cents INTEGER,
        net_payout_cents INTEGER,
        currency_code TEXT,
        failure_reason TEXT,
        failure_code TEXT,
        ledger_entry_count INTEGER,
        eligible_after TEXT,
        processed_at TEXT,
        funding_source TEXT,
        wallet_debit_cents INTEGER,
        charge_cents INTEGER,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');
});

function seedPayoutProfessional(string $id, string $handle, string $name): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => $name,
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function seedCommissionPayoutRow(array $attrs): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'status' => 'completed',
        'gross_commission_cents' => 1000,
        'platform_fee_cents' => 100,
        'net_payout_cents' => 900,
        'currency_code' => 'USD',
        'ledger_entry_count' => 3,
        'created_at' => $now,
        'updated_at' => $now,
    ], $attrs));
}

function makePayoutsController(CommissionPayoutService $payoutService): StripeConnectController
{
    $connectStub = Mockery::mock(StripeConnectService::class);

    return new StripeConnectController($connectStub, $payoutService);
}

function makePayoutsRequest(Professional $pro, array $query = []): PayoutsRequest
{
    $query = array_merge(['role' => 'affiliate'], $query);
    $request = PayoutsRequest::create('/api/stripe/payouts', 'GET', $query);
    $request->attributes->set('professional', $pro);

    return $request;
}

it('lists payouts where the professional is the affiliate by default', function () {
    seedPayoutProfessional('pro-brand-1', 'brandone', 'Brand One');
    seedPayoutProfessional('pro-brand-2', 'brandtwo', 'Brand Two');
    seedPayoutProfessional('pro-aff-1', 'affone', 'Affiliate One');

    // Two payouts where this user is the affiliate, one where they're
    // (improbably) the brand-side — the affiliate filter must return only
    // the first two.
    seedCommissionPayoutRow([
        'id' => 'pay-aff-1',
        'brand_professional_id' => 'pro-brand-1',
        'affiliate_professional_id' => 'pro-aff-1',
        'net_payout_cents' => 900,
    ]);
    seedCommissionPayoutRow([
        'id' => 'pay-aff-2',
        'brand_professional_id' => 'pro-brand-2',
        'affiliate_professional_id' => 'pro-aff-1',
        'net_payout_cents' => 1500,
    ]);
    seedCommissionPayoutRow([
        'id' => 'pay-reversed',
        'brand_professional_id' => 'pro-aff-1',
        'affiliate_professional_id' => 'pro-brand-1',
        'net_payout_cents' => 7000,
    ]);

    $summary = Mockery::mock(CommissionPayoutService::class);
    $summary->shouldReceive('getPayoutSummary')->andReturn([
        'total_earned_cents' => 2400,
        'pending_cents' => 0,
    ]);

    $affiliate = Professional::query()->find('pro-aff-1');
    $response = makePayoutsController($summary)->payouts(makePayoutsRequest($affiliate));

    expect($response->status())->toBe(200);
    $data = $response->getData(true);

    $ids = collect($data['payouts'])->pluck('id')->all();
    expect($data['payouts'])->toHaveCount(2);
    expect($ids)->toContain('pay-aff-1', 'pay-aff-2');
    expect($ids)->not->toContain('pay-reversed');

    // Response shape includes the nested brand relation (id/name/handle) via
    // the eager-loaded Professional model.
    $first = collect($data['payouts'])->firstWhere('id', 'pay-aff-1');
    expect($first['brand']['handle'])->toBe('brandone');
    expect($first['brand']['name'])->toBe('Brand One');
    expect($first['net_payout_cents'])->toBe(900);
    expect($first['currency_code'])->toBe('USD');

    expect($data['summary'])->toEqual([
        'total_earned_cents' => 2400,
        'pending_cents' => 0,
    ]);
});

it('lists payouts where the professional is the brand when role=brand', function () {
    seedPayoutProfessional('pro-brand-1', 'brandone', 'Brand One');
    seedPayoutProfessional('pro-aff-1', 'affone', 'Affiliate One');
    seedPayoutProfessional('pro-aff-2', 'afftwo', 'Affiliate Two');

    seedCommissionPayoutRow([
        'id' => 'pay-b-1',
        'brand_professional_id' => 'pro-brand-1',
        'affiliate_professional_id' => 'pro-aff-1',
    ]);
    seedCommissionPayoutRow([
        'id' => 'pay-b-2',
        'brand_professional_id' => 'pro-brand-1',
        'affiliate_professional_id' => 'pro-aff-2',
    ]);
    seedCommissionPayoutRow([
        'id' => 'pay-b-other',
        'brand_professional_id' => 'pro-aff-1',
        'affiliate_professional_id' => 'pro-brand-1',
    ]);

    $summary = Mockery::mock(CommissionPayoutService::class);
    $summary->shouldReceive('getPayoutSummary')->andReturn([]);

    $brand = Professional::query()->find('pro-brand-1');
    $response = makePayoutsController($summary)->payouts(makePayoutsRequest($brand, ['role' => 'brand']));

    expect($response->status())->toBe(200);
    $data = $response->getData(true);

    $ids = collect($data['payouts'])->pluck('id')->all();
    expect($data['payouts'])->toHaveCount(2);
    expect($ids)->toContain('pay-b-1', 'pay-b-2');
    expect($ids)->not->toContain('pay-b-other');

    // Brand-view responses surface the affiliate relation alongside the brand.
    $first = collect($data['payouts'])->firstWhere('id', 'pay-b-1');
    expect($first['affiliate']['handle'])->toBe('affone');
    expect($first['affiliate']['name'])->toBe('Affiliate One');
});

it('returns an empty payout list but still includes the summary when there are no payouts', function () {
    seedPayoutProfessional('pro-empty-1', 'emptybrand', 'Empty Brand');

    $summary = Mockery::mock(CommissionPayoutService::class);
    $summary->shouldReceive('getPayoutSummary')->andReturn([
        'total_earned_cents' => 0,
        'pending_cents' => 0,
    ]);

    $pro = Professional::query()->find('pro-empty-1');
    $response = makePayoutsController($summary)->payouts(makePayoutsRequest($pro));

    expect($response->status())->toBe(200);
    $data = $response->getData(true);

    expect($data['payouts'])->toBeEmpty();
    expect($data['summary'])->toEqual([
        'total_earned_cents' => 0,
        'pending_cents' => 0,
    ]);
});
