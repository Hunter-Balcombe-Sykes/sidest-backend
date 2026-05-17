<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Http\Requests\Api\Professional\Stripe\PayoutsRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\ExportService;
use App\Services\Stripe\StripeBalanceService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeTransactionFetcher;
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
        payment_intent_id TEXT,
        charge_id TEXT,
        status TEXT,
        gross_commission_cents INTEGER,
        platform_fee_cents INTEGER,
        net_payout_cents INTEGER,
        currency_code TEXT,
        failure_reason TEXT,
        failure_code TEXT,
        failure_category TEXT,
        ledger_entry_count INTEGER,
        eligible_after TEXT,
        processed_at TEXT,
        charge_cents INTEGER,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
        transfer_completed_at TEXT,
        last_retry_at TEXT,
        funding_failure_count INTEGER NOT NULL DEFAULT 0,
        grace_notifications_sent TEXT,
        grace_started_at TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    // CommissionPolicy::view falls through to BrandAccessService::canReadBrandFinancialAnalytics
    // for non-owner non-affiliate callers, which queries this table. Empty in tests = denyAsNotFound().
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_team_memberships (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NOT NULL,
        member_professional_id TEXT NOT NULL,
        role TEXT NOT NULL,
        status TEXT NOT NULL,
        created_at TEXT,
        updated_at TEXT
    )');
});

function seedPayoutProfessional(string $id, string $handle, string $name, string $type = 'brand'): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => $name,
        // 'brand' for brand-side rows, 'influencer' for affiliates. CommissionPolicy::viewOwnPayouts
        // gates on type (brands → role=brand only, non-brands → role=affiliate only).
        'professional_type' => $type,
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
    $fetcherStub = Mockery::mock(StripeTransactionFetcher::class);
    $balanceStub = Mockery::mock(StripeBalanceService::class);

    return new StripeConnectController(
        $connectStub,
        $payoutService,
        $fetcherStub,
        $balanceStub,
        Mockery::mock(ExportService::class),
        app(CacheLockService::class),
    );
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
    seedPayoutProfessional('pro-aff-1', 'affone', 'Affiliate One', 'influencer');

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
    seedPayoutProfessional('pro-aff-1', 'affone', 'Affiliate One', 'influencer');
    seedPayoutProfessional('pro-aff-2', 'afftwo', 'Affiliate Two', 'influencer');

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
    seedPayoutProfessional('pro-empty-1', 'emptyaff', 'Empty Affiliate', 'influencer');

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

// #STRIPE-1 — CommissionPolicy::viewOwnPayouts replaces inline role-scoping.
// Cross-role calls (affiliate using ?role=brand, or vice versa) now return 403
// instead of an empty 200, surfacing the rule in one testable place.

it('denies a brand calling /stripe/payouts with role=affiliate (cross-role)', function () {
    seedPayoutProfessional('pro-brand-x', 'brandx', 'Brand X', 'brand');

    $summary = Mockery::mock(CommissionPayoutService::class);
    $summary->shouldNotReceive('getPayoutSummary');

    $brand = Professional::query()->find('pro-brand-x');

    expect(fn () => makePayoutsController($summary)->payouts(
        makePayoutsRequest($brand, ['role' => 'affiliate'])
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('denies an affiliate calling /stripe/payouts with role=brand (cross-role)', function () {
    seedPayoutProfessional('pro-aff-x', 'affx', 'Affiliate X', 'influencer');

    $summary = Mockery::mock(CommissionPayoutService::class);
    $summary->shouldNotReceive('getPayoutSummary');

    $aff = Professional::query()->find('pro-aff-x');

    expect(fn () => makePayoutsController($summary)->payouts(
        makePayoutsRequest($aff, ['role' => 'brand'])
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

// ─── Phase 2: filters + cursor pagination ───────────────────────────────────

it('filters payouts by status[]', function () {
    seedPayoutProfessional('pro-aff-f1', 'afff1', 'Aff F1', 'influencer');
    seedPayoutProfessional('pro-brand-f1', 'brandf1', 'Brand F1');

    foreach (['completed', 'failed', 'completed', 'pending'] as $i => $status) {
        seedCommissionPayoutRow([
            'id' => "pay-fil-{$i}",
            'brand_professional_id' => 'pro-brand-f1',
            'affiliate_professional_id' => 'pro-aff-f1',
            'status' => $status,
        ]);
    }

    $summary = Mockery::mock(CommissionPayoutService::class);
    $summary->shouldReceive('getPayoutSummary')->andReturn([]);

    $aff = Professional::query()->find('pro-aff-f1');
    $response = makePayoutsController($summary)->payouts(
        makePayoutsRequest($aff, ['status' => ['completed']])
    );

    $data = $response->getData(true);
    expect($data['payouts'])->toHaveCount(2);
    foreach ($data['payouts'] as $p) {
        expect($p['status'])->toBe('completed');
    }
});

it('filters payouts by date_from', function () {
    seedPayoutProfessional('pro-aff-f2', 'afff2', 'Aff F2', 'influencer');
    seedPayoutProfessional('pro-brand-f2', 'brandf2', 'Brand F2');

    seedCommissionPayoutRow([
        'id' => 'pay-old',
        'brand_professional_id' => 'pro-brand-f2',
        'affiliate_professional_id' => 'pro-aff-f2',
        'created_at' => '2026-01-01 12:00:00',
    ]);
    seedCommissionPayoutRow([
        'id' => 'pay-new',
        'brand_professional_id' => 'pro-brand-f2',
        'affiliate_professional_id' => 'pro-aff-f2',
        'created_at' => '2026-05-01 12:00:00',
    ]);

    $summary = Mockery::mock(CommissionPayoutService::class);
    $summary->shouldReceive('getPayoutSummary')->andReturn([]);

    $aff = Professional::query()->find('pro-aff-f2');
    $response = makePayoutsController($summary)->payouts(
        makePayoutsRequest($aff, ['date_from' => '2026-03-01'])
    );

    $data = $response->getData(true);
    expect(collect($data['payouts'])->pluck('id')->all())->toBe(['pay-new']);
});

it('paginates payouts via cursor with stable has_more flag', function () {
    seedPayoutProfessional('pro-aff-p', 'affp', 'Aff P', 'influencer');
    seedPayoutProfessional('pro-brand-p', 'brandp', 'Brand P');

    // 5 payouts, fetch limit=2 — expect cursor + has_more=true on page 1,
    // then has_more=false on the final page.
    for ($i = 0; $i < 5; $i++) {
        seedCommissionPayoutRow([
            'id' => "pay-p-{$i}",
            'brand_professional_id' => 'pro-brand-p',
            'affiliate_professional_id' => 'pro-aff-p',
            'created_at' => '2026-05-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT).' 10:00:00',
        ]);
    }

    $summary = Mockery::mock(CommissionPayoutService::class);
    $summary->shouldReceive('getPayoutSummary')->andReturn([]);

    $aff = Professional::query()->find('pro-aff-p');

    $first = makePayoutsController($summary)->payouts(
        makePayoutsRequest($aff, ['limit' => 2])
    )->getData(true);

    expect($first['payouts'])->toHaveCount(2);
    expect($first['pagination']['has_more'])->toBeTrue();
    expect($first['pagination']['cursor'])->not->toBeNull();

    // Drive page 2 with the returned cursor.
    $second = makePayoutsController($summary)->payouts(
        makePayoutsRequest($aff, ['limit' => 2, 'cursor' => $first['pagination']['cursor']])
    )->getData(true);

    expect($second['payouts'])->toHaveCount(2);
    expect($second['pagination']['has_more'])->toBeTrue();

    // Page 3 finishes the list.
    $third = makePayoutsController($summary)->payouts(
        makePayoutsRequest($aff, ['limit' => 2, 'cursor' => $second['pagination']['cursor']])
    )->getData(true);

    expect($third['payouts'])->toHaveCount(1);
    expect($third['pagination']['has_more'])->toBeFalse();
});

// ─── Phase 2: GET /stripe/payouts/{id} payoutDetail ─────────────────────────

it('payoutDetail returns the payout and its linked orders for the affiliate', function () {
    setupCommerceOrdersTables();

    seedPayoutProfessional('pro-aff-d1', 'affd1', 'Aff D1', 'influencer');
    seedPayoutProfessional('pro-brand-d1', 'brandd1', 'Brand D1');

    seedCommissionPayoutRow([
        'id' => 'pay-detail-1',
        'brand_professional_id' => 'pro-brand-d1',
        'affiliate_professional_id' => 'pro-aff-d1',
        'gross_commission_cents' => 5000,
        'ledger_entry_count' => 2,
    ]);

    $now = now()->toDateTimeString();
    foreach (['ord-1', 'ord-2'] as $i => $orderId) {
        \Illuminate\Support\Facades\DB::connection('pgsql')->table('commerce.orders')->insert([
            'id' => $orderId,
            'shopify_order_id' => 'shop_'.$orderId,
            'shopify_shop_domain' => 'd1.myshopify.com',
            'brand_professional_id' => 'pro-brand-d1',
            'affiliate_professional_id' => 'pro-aff-d1',
            'status' => 'approved',
            'gross_cents' => 5000,
            'commission_cents' => 2500,
            'refund_cents' => 0,
            'net_cents' => 5000,
            'commission_rate' => 50,
            'rate_source' => 'brand_default',
            'currency_code' => 'AUD',
            'payout_id' => 'pay-detail-1',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $summary = Mockery::mock(CommissionPayoutService::class);
    $summary->shouldNotReceive('getPayoutSummary');

    $aff = Professional::query()->find('pro-aff-d1');
    $request = \Illuminate\Http\Request::create('/api/stripe/payouts/pay-detail-1', 'GET');
    $request->attributes->set('professional', $aff);

    $response = makePayoutsController($summary)->payoutDetail($request, 'pay-detail-1');

    expect($response->status())->toBe(200);
    $data = $response->getData(true);
    expect($data['payout']['id'])->toBe('pay-detail-1');
    expect($data['payout']['gross_commission_cents'])->toBe(5000);
    expect($data['orders'])->toHaveCount(2);
    expect(collect($data['orders'])->pluck('id')->all())->toContain('ord-1', 'ord-2');
});

it('payoutDetail returns 404 for missing payout via findOrFail', function () {
    seedPayoutProfessional('pro-aff-d2', 'affd2', 'Aff D2', 'influencer');

    $summary = Mockery::mock(CommissionPayoutService::class);
    $aff = Professional::query()->find('pro-aff-d2');

    $request = \Illuminate\Http\Request::create('/api/stripe/payouts/does-not-exist', 'GET');
    $request->attributes->set('professional', $aff);

    // findOrFail → ModelNotFoundException → rendered as JSON 404 "Resource not found"
    // by bootstrap/app.php's exception handler at request time. Same envelope as the
    // cross-tenant policy denial, unlike NotFoundHttpException.
    expect(fn () => makePayoutsController($summary)->payoutDetail($request, 'does-not-exist'))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('payoutDetail denies a foreign payout via CommissionPolicy (cross-tenant)', function () {
    seedPayoutProfessional('pro-aff-d3', 'affd3', 'Aff D3', 'influencer');
    seedPayoutProfessional('pro-aff-other', 'affother', 'Aff Other', 'influencer');
    seedPayoutProfessional('pro-brand-d3', 'brandd3', 'Brand D3');

    seedCommissionPayoutRow([
        'id' => 'pay-foreign',
        'brand_professional_id' => 'pro-brand-d3',
        'affiliate_professional_id' => 'pro-aff-other',
    ]);

    $summary = Mockery::mock(CommissionPayoutService::class);
    $aff = Professional::query()->find('pro-aff-d3');

    $request = \Illuminate\Http\Request::create('/api/stripe/payouts/pay-foreign', 'GET');
    $request->attributes->set('professional', $aff);

    // CommissionPolicy::view denies cross-tenant via denyAsNotFound() — the Gate throws
    // AuthorizationException with status 404, which the bootstrap handler renders as 404.
    expect(fn () => makePayoutsController($summary)->payoutDetail($request, 'pay-foreign'))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});
