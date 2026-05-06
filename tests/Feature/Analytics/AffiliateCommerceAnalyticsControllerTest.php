<?php

use App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->controller = app(AffiliateCommerceAnalyticsController::class);
    $this->professional = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'UTC']);
    Cache::flush();
});

/**
 * Stub DB::connection() so driver-detection calls don't blow up when DB is mocked.
 */
function affiliateStubDbConnection(string $driver = 'pgsql'): void
{
    $connMock = Mockery::mock(\Illuminate\Database\Connection::class);
    $connMock->shouldReceive('getDriverName')->andReturn($driver);
    DB::shouldReceive('connection')->andReturn($connMock);
}

/**
 * Stubs all sub-queries that aren't under test in these unit tests:
 * payout summary, grace summary, brand breakdown, and per-key order sub-queries.
 * Returns empty/zero shapes so main assertions stay isolated.
 */
function affiliateStubExtras(): void
{
    // payout summary — commerce.commission_payouts (two calls)
    $pendingMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereIn', 'selectRaw'] as $method) {
        $pendingMock->shouldReceive($method)->andReturnSelf();
    }
    $pendingMock->shouldReceive('first')->andReturn(
        (object) ['net_pending_cents' => 0, 'next_eligible_at' => null, 'currency_code' => 'AUD']
    );

    $lastPaidMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'orderByDesc', 'select'] as $method) {
        $lastPaidMock->shouldReceive($method)->andReturnSelf();
    }
    $lastPaidMock->shouldReceive('first')->andReturn(null);

    DB::shouldReceive('table')->with('commerce.commission_payouts')
        ->twice()
        ->andReturn($pendingMock, $lastPaidMock);

    // grace summary — core.professionals returns active → early return (no further queries)
    $proMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereNull', 'select'] as $method) {
        $proMock->shouldReceive($method)->andReturnSelf();
    }
    $proMock->shouldReceive('first')->andReturn((object) ['stripe_connect_status' => 'active']);
    DB::shouldReceive('table')->with('core.professionals')->andReturn($proMock);

    // brand breakdown rollup — empty collection so isEmpty() works correctly
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'selectRaw', 'leftJoin', 'join', 'groupBy', 'orderByRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('get')->andReturn(collect());
    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup as r')->andReturn($rollupMock);
}

it('throws ValidationException when from is provided without to', function () {
    $request = Request::create('/', 'GET', ['from' => '2026-04-01']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('throws ValidationException when to is provided without from', function () {
    $request = Request::create('/', 'GET', ['to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('throws ValidationException when from is after to', function () {
    $request = Request::create('/', 'GET', ['from' => '2026-04-19', 'to' => '2026-04-01']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('throws ValidationException for invalid date format', function () {
    $request = Request::create('/', 'GET', ['from' => '19-04-2026', 'to' => '20-04-2026']);
    $request->attributes->set('professional', $this->professional);

    expect(fn () => $this->controller->overview($request))
        ->toThrow(ValidationException::class);
});

it('returns zero totals and empty timeseries when no commerce.orders data exists', function () {
    affiliateStubDbConnection();
    // All commerce.orders queries return empty
    $ordersMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereNotIn', 'whereIn', 'whereNull', 'whereNotNull', 'whereBetween', 'selectRaw',
        'groupBy', 'groupByRaw', 'orderByDesc', 'orderByRaw'] as $m) {
        $ordersMock->shouldReceive($m)->andReturnSelf();
    }
    $ordersMock->shouldReceive('first')->andReturn(null);
    $ordersMock->shouldReceive('get')->andReturn(collect());

    // Rollup: no reversed commission (non-aliased, used for reversed_cents)
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'selectRaw', 'leftJoin', 'join', 'groupBy', 'orderByRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('first')->andReturn((object) ['reversed_cents' => 0]);
    $rollupMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')->with('commerce.orders')->andReturn($ordersMock);
    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    affiliateStubExtras();

    $request = Request::create('/', 'GET', ['from' => '2026-04-01', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['range'])->toBe(['from' => '2026-04-01', 'to' => '2026-04-19'])
        ->and($data['totals']['orders_count'])->toBe(0)
        ->and($data['totals']['gross_cents'])->toBe(0)
        ->and($data['totals']['commission_accrued_cents'])->toBe(0)
        ->and($data['timeseries'])->toBe([]);
});

it('defaults to last 30 days when no range params are given', function () {
    affiliateStubDbConnection();
    $ordersMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereNotIn', 'whereIn', 'whereNull', 'whereNotNull', 'selectRaw',
        'groupBy', 'groupByRaw', 'orderByDesc', 'orderByRaw'] as $m) {
        $ordersMock->shouldReceive($m)->andReturnSelf();
    }
    $ordersMock->shouldReceive('first')->andReturn(null);
    $ordersMock->shouldReceive('get')->andReturn(collect());

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'selectRaw', 'leftJoin', 'join', 'groupBy', 'orderByRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('first')->andReturn((object) ['reversed_cents' => 0]);
    $rollupMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')->with('commerce.orders')->andReturn($ordersMock);
    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    affiliateStubExtras();

    $request = Request::create('/', 'GET');
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['range']['from'])->toBe(now()->subDays(29)->toDateString())
        ->and($data['range']['to'])->toBe(now()->toDateString());
});

it('returns timeseries from commerce.orders rows grouped by day', function () {
    affiliateStubDbConnection();
    // Totals first() returns aggregated row
    $totalsResult = (object) [
        'orders_count' => 4,
        'gross_cents' => 17000,
        'refunded_cents' => 0,
        'net_cents' => 17000,
        'commission_cents' => 1700,
    ];

    $currencyResult = (object) ['currency_code' => 'AUD', 'cnt' => 4];

    // Timeseries rows (include all fields the controller maps)
    $timeseriesRows = collect([
        (object) ['bucket' => '2026-04-18', 'orders_count' => 3, 'gross_cents' => 12000, 'net_cents' => 12000, 'commission_accrued_cents' => 1200],
        (object) ['bucket' => '2026-04-19', 'orders_count' => 1, 'gross_cents' => 5000, 'net_cents' => 5000, 'commission_accrued_cents' => 500],
    ]);

    $ordersMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereNotIn', 'whereIn', 'whereNull', 'whereNotNull', 'selectRaw',
        'groupBy', 'groupByRaw', 'orderByDesc', 'orderByRaw'] as $m) {
        $ordersMock->shouldReceive($m)->andReturnSelf();
    }
    // first() called multiple times: totals, currency, paid_cents
    $ordersMock->shouldReceive('first')
        ->andReturn($totalsResult, $currencyResult, (object) ['paid_cents' => 0]);
    $ordersMock->shouldReceive('get')->andReturn($timeseriesRows);

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'selectRaw', 'leftJoin', 'join', 'groupBy', 'orderByRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('first')->andReturn((object) ['reversed_cents' => 0]);
    $rollupMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')->with('commerce.orders')->andReturn($ordersMock);
    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    affiliateStubExtras();

    $request = Request::create('/', 'GET', ['from' => '2026-04-18', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['totals']['orders_count'])->toBe(4)
        ->and($data['totals']['gross_cents'])->toBe(17000)
        ->and($data['totals']['currency_code'])->toBe('AUD')
        ->and($data['timeseries'])->toHaveCount(2)
        ->and($data['timeseries'][0]['bucket'])->toBe('2026-04-18')
        ->and($data['timeseries'][0]['orders_count'])->toBe(3);
});
