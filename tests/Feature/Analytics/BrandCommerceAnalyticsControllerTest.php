<?php

use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->controller = app(BrandCommerceAnalyticsController::class);
    $this->professional = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'UTC']);
    Cache::flush();
});

/**
 * Returns a Mockery query builder stub that emits an empty collection/null
 * for all query chain calls used by the brand analytics controller.
 */
function brandEmptyQueryMock(): \Illuminate\Database\Query\Builder
{
    $mock = Mockery::mock(\Illuminate\Database\Query\Builder::class);

    foreach (['where', 'whereNotIn', 'whereIn', 'whereNull', 'whereNotNull', 'whereBetween', 'leftJoin', 'join',
        'select', 'selectRaw', 'groupBy', 'groupByRaw', 'orderBy', 'orderByDesc', 'orderByRaw', 'limit'] as $m) {
        $mock->shouldReceive($m)->andReturnSelf();
    }
    $mock->shouldReceive('get')->andReturn(collect());
    $mock->shouldReceive('first')->andReturn(null);
    $mock->shouldReceive('pluck')->andReturn(collect());
    $mock->shouldReceive('count')->andReturn(0);

    return $mock;
}

/**
 * Stub DB::connection() so driver-detection calls don't blow up when DB is mocked.
 * The brand/affiliate controllers call DB::connection('pgsql')->getDriverName() for SQLite compat.
 */
function stubDbConnection(string $driver = 'pgsql'): void
{
    $connMock = Mockery::mock(\Illuminate\Database\Connection::class);
    $connMock->shouldReceive('getDriverName')->andReturn($driver);

    DB::shouldReceive('connection')->andReturn($connMock);
}

it('throws ValidationException when from is provided without to', function () {
    $request = Request::create('/', 'GET', ['from' => '2026-04-01']);
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

it('returns correct empty response shape when no data exists', function () {
    stubDbConnection();
    // Stub all DB::table calls the controller makes — all return empty results
    $defaultMock = brandEmptyQueryMock();
    DB::shouldReceive('table')->with('commerce.orders')->andReturn($defaultMock);
    // paid_cents query now JOINs via aliased table — stub it separately
    DB::shouldReceive('table')->with('commerce.orders as o')->andReturn($defaultMock);
    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($defaultMock);
    DB::shouldReceive('table')->with('analytics.site_visits')->andReturn($defaultMock);
    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($defaultMock);

    $request = Request::create('/', 'GET', ['from' => '2026-04-01', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['range'])->toBe(['from' => '2026-04-01', 'to' => '2026-04-19'])
        ->and($data['totals']['orders_count'])->toBe(0)
        ->and($data['totals']['gross_cents'])->toBe(0)
        ->and($data['affiliates'])->toBe([])
        ->and($data['timeseries'])->toBe([])
        ->and($data['commission_summary']['pending_cents'])->toBe(0)
        ->and($data['commission_summary']['approved_cents'])->toBe(0)
        ->and($data['commission_summary']['paid_cents'])->toBe(0)
        ->and($data['commission_summary']['reversed_cents'])->toBe(0);
});

it('builds affiliate breakdown from brand_affiliate_rollup rows', function () {
    stubDbConnection();
    $affiliateId = (string) Str::uuid();

    // brand_affiliate_rollup: first get() returns affiliates, subsequent get() returns empty (commission reversed),
    // first() returns reversed_cents = 0
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereNotIn', 'whereNull', 'whereBetween', 'selectRaw', 'groupBy',
        'orderByRaw', 'limit'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $affiliateRows = collect([
        (object) [
            'affiliate_professional_id' => $affiliateId,
            'orders_count' => 7,
            'gross_cents' => 35000,
            'net_cents' => 35000,
            'commission_net_cents' => 3500,
        ],
    ]);
    $callCount = 0;
    $rollupMock->shouldReceive('get')->andReturnUsing(function () use (&$callCount, $affiliateRows) {
        $callCount++;

        // First get() = affiliate breakdown; subsequent = empty (commission summary reversed)
        return $callCount === 1 ? $affiliateRows : collect();
    });
    $rollupMock->shouldReceive('first')->andReturn((object) ['reversed_cents' => 0]);

    // Identity lookup
    $identityMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['whereIn', 'whereNull', 'select'] as $m) {
        $identityMock->shouldReceive($m)->andReturnSelf();
    }
    $identityMock->shouldReceive('get')->andReturn(collect([
        (object) ['id' => $affiliateId, 'display_name' => 'Alice', 'first_name' => 'Alice', 'last_name' => 'Smith', 'handle' => 'alice'],
    ])->keyBy('id'));

    // All commerce.orders calls return empty/zeros
    $defaultMock = brandEmptyQueryMock();

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);
    DB::shouldReceive('table')->with('core.professionals')->andReturn($identityMock);
    DB::shouldReceive('table')->with('commerce.orders')->andReturn($defaultMock);
    // paid_cents query now JOINs via aliased table — stub it with empty result
    DB::shouldReceive('table')->with('commerce.orders as o')->andReturn($defaultMock);
    DB::shouldReceive('table')->with('analytics.site_visits')->andReturn($defaultMock);
    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($defaultMock);

    $request = Request::create('/', 'GET', ['from' => '2026-04-18', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['affiliates'])->toHaveCount(1)
        ->and($data['affiliates'][0]['affiliate_professional_id'])->toBe($affiliateId)
        ->and($data['affiliates'][0]['orders_count'])->toBe(7)
        ->and($data['affiliates'][0]['gross_cents'])->toBe(35000)
        ->and($data['affiliates'][0]['commission_net_cents'])->toBe(3500);
});

it('derives commission_summary pending_cents as approved minus paid minus reversed', function () {
    stubDbConnection();

    // Comprehensive commerce.orders mock: handles totals, currency detection, timeseries,
    // and approved_cents calls. paid_cents now uses a separate aliased builder (see below).
    $ordersMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereBetween',
        'selectRaw', 'groupBy', 'groupByRaw', 'orderBy', 'orderByDesc', 'orderByRaw', 'limit'] as $m) {
        $ordersMock->shouldReceive($m)->andReturnSelf();
    }
    // first() is called three times on the unaliased builder:
    //   1st: totals (orders_count etc.)         → zeros
    //   2nd: currency detection                  → AUD
    //   3rd: approved_cents (commission summary) → 10000
    $callCount = 0;
    $ordersMock->shouldReceive('first')->andReturnUsing(function () use (&$callCount) {
        $callCount++;

        return match ($callCount) {
            1 => (object) ['orders_count' => 0, 'gross_cents' => 0, 'refunded_cents' => 0, 'net_cents' => 0],
            2 => (object) ['currency_code' => 'AUD', 'cnt' => 0],
            3 => (object) ['approved_cents' => 10000],
            default => null,
        };
    });
    $ordersMock->shouldReceive('get')->andReturn(collect()); // timeseries

    // paid_cents is now fetched via JOIN on the aliased builder (commerce.orders as o).
    $paidMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereIn', 'whereNotIn', 'join', 'selectRaw'] as $m) {
        $paidMock->shouldReceive($m)->andReturnSelf();
    }
    $paidMock->shouldReceive('first')->andReturn((object) ['paid_cents' => 3000]);

    // brand_affiliate_rollup: reversed_cents for commission summary AND rollup for affiliates
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'selectRaw', 'groupBy', 'orderByRaw', 'limit'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('first')->andReturn((object) ['reversed_cents' => 1000]);
    $rollupMock->shouldReceive('get')->andReturn(collect()); // affiliate breakdown

    // Everything else returns empty
    $defaultMock = brandEmptyQueryMock();

    DB::shouldReceive('table')->with('commerce.orders')->andReturn($ordersMock);
    DB::shouldReceive('table')->with('commerce.orders as o')->andReturn($paidMock);
    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);
    DB::shouldReceive('table')->with('analytics.site_visits')->andReturn($defaultMock);
    DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($defaultMock);

    $request = Request::create('/', 'GET', ['from' => '2026-04-01', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $summary = json_decode($response->getContent(), true)['commission_summary'];

    // pending = max(0, approved - paid - reversed) = max(0, 10000 - 3000 - 1000) = 6000
    expect($summary['approved_cents'])->toBe(10000)
        ->and($summary['paid_cents'])->toBe(3000)
        ->and($summary['reversed_cents'])->toBe(1000)
        ->and($summary['pending_cents'])->toBe(6000);
});
