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
 * Stubs the three overview() sub-queries that aren't under test in the
 * analytics unit tests: brand breakdown, payout summary, grace summary.
 * Returns empty/zero shapes so the main timeseries assertions stay clean.
 */
function stubOverviewExtras(): void
{
    // DB::raw() is called on the facade directly by buildBrandBreakdown's select() calls
    DB::shouldReceive('raw')->andReturnUsing(fn ($v) => new \Illuminate\Database\Query\Expression($v));

    // brand breakdown — analytics.brand_affiliate_daily as bad → empty
    $brandMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['leftJoin', 'where', 'whereBetween', 'select', 'selectRaw', 'groupBy', 'orderByDesc'] as $method) {
        $brandMock->shouldReceive($method)->andReturnSelf();
    }
    $brandMock->shouldReceive('get')->andReturn(collect());
    DB::shouldReceive('table')->with('analytics.brand_affiliate_daily as bad')->andReturn($brandMock);

    // payout summary — two calls to commerce.commission_payouts
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

    // grace summary — core.professionals lookup returns active → early return (no further queries)
    $proMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereNull', 'select'] as $method) {
        $proMock->shouldReceive($method)->andReturnSelf();
    }
    $proMock->shouldReceive('first')->andReturn((object) ['stripe_connect_status' => 'active']);
    DB::shouldReceive('table')->with('core.professionals')->andReturn($proMock);
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

it('returns zero totals and empty timeseries when no data exists', function () {

    $queryMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $queryMock->shouldReceive('where')->andReturnSelf();
    $queryMock->shouldReceive('whereBetween')->andReturnSelf();
    $queryMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')
        ->with('analytics.professional_metrics_daily')
        ->once()
        ->andReturn($queryMock);

    stubOverviewExtras();

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

    $queryMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $queryMock->shouldReceive('where')->andReturnSelf();
    $queryMock->shouldReceive('whereBetween')->andReturnSelf();
    $queryMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')
        ->with('analytics.professional_metrics_daily')
        ->andReturn($queryMock);

    stubOverviewExtras();

    $request = Request::create('/', 'GET');
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['range']['from'])->toBe(now()->subDays(29)->toDateString())
        ->and($data['range']['to'])->toBe(now()->toDateString());
});

it('returns timeseries from daily rows', function () {

    stubOverviewExtras();

    $queryMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $queryMock->shouldReceive('where')->andReturnSelf();
    $queryMock->shouldReceive('whereBetween')->andReturnSelf();
    $queryMock->shouldReceive('get')->andReturn(collect([
        (object) [
            'day' => '2026-04-18',
            'currency_code' => 'AUD',
            'orders_count' => 3,
            'gross_cents' => 12000,
            'refunded_cents' => 0,
            'net_cents' => 12000,
            'commission_accrued_cents' => 1200,
            'commission_reversed_cents' => 0,
            'commission_paid_cents' => 0,
        ],
        (object) [
            'day' => '2026-04-19',
            'currency_code' => 'AUD',
            'orders_count' => 1,
            'gross_cents' => 5000,
            'refunded_cents' => 0,
            'net_cents' => 5000,
            'commission_accrued_cents' => 500,
            'commission_reversed_cents' => 0,
            'commission_paid_cents' => 0,
        ],
    ]));

    DB::shouldReceive('table')
        ->with('analytics.professional_metrics_daily')
        ->andReturn($queryMock);

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
