<?php

use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->controller = new BrandCommerceAnalyticsController();
    $this->professional = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'UTC']);
    Cache::flush();
});

// Helper: returns a Mockery query builder that yields an empty collection.
function emptyQueryMock(): \Illuminate\Database\Query\Builder
{
    $mock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $mock->shouldReceive('where')->andReturnSelf();
    $mock->shouldReceive('whereBetween')->andReturnSelf();
    $mock->shouldReceive('get')->andReturn(collect());
    return $mock;
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
    DB::shouldReceive('table')->with('analytics.brand_metrics_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_affiliate_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_commission_daily')->andReturn(emptyQueryMock());

    $request = Request::create('/', 'GET', ['from' => '2026-04-01', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['range'])->toBe(['from' => '2026-04-01', 'to' => '2026-04-19'])
        ->and($data['totals']['orders_count'])->toBe(0)
        ->and($data['affiliates'])->toBe([])
        ->and($data['timeseries'])->toBe([])
        ->and($data['commission_summary']['pending_cents'])->toBe(0)
        ->and($data['commission_summary']['approved_cents'])->toBe(0)
        ->and($data['commission_summary']['paid_cents'])->toBe(0)
        ->and($data['commission_summary']['reversed_cents'])->toBe(0);
});

it('groups affiliate breakdown by affiliate_professional_id', function () {
    $affiliateId = (string) Str::uuid();

    $affiliateMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $affiliateMock->shouldReceive('where')->andReturnSelf();
    $affiliateMock->shouldReceive('whereBetween')->andReturnSelf();
    $affiliateMock->shouldReceive('get')->andReturn(collect([
        (object) [
            'day' => '2026-04-18',
            'affiliate_professional_id' => $affiliateId,
            'currency_code' => 'AUD',
            'orders_count' => 5,
            'gross_cents' => 25000,
            'refunded_cents' => 0,
            'net_cents' => 25000,
            'commission_net_cents' => 2500,
            'customers_count' => 3,
        ],
        (object) [
            'day' => '2026-04-19',
            'affiliate_professional_id' => $affiliateId,
            'currency_code' => 'AUD',
            'orders_count' => 2,
            'gross_cents' => 10000,
            'refunded_cents' => 0,
            'net_cents' => 10000,
            'commission_net_cents' => 1000,
            'customers_count' => 1,
        ],
    ]));

    DB::shouldReceive('table')->with('analytics.brand_metrics_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_affiliate_daily')->andReturn($affiliateMock);
    DB::shouldReceive('table')->with('analytics.brand_commission_daily')->andReturn(emptyQueryMock());

    $request = Request::create('/', 'GET', ['from' => '2026-04-18', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['affiliates'])->toHaveCount(1)
        ->and($data['affiliates'][0]['affiliate_professional_id'])->toBe($affiliateId)
        ->and($data['affiliates'][0]['orders_count'])->toBe(7)
        ->and($data['affiliates'][0]['gross_cents'])->toBe(35000)
        ->and($data['affiliates'][0]['commission_net_cents'])->toBe(3500)
        ->and($data['affiliates'][0]['customers_count'])->toBe(4);
});

it('summarises commission rows by payout_status', function () {
    $commissionMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $commissionMock->shouldReceive('where')->andReturnSelf();
    $commissionMock->shouldReceive('whereBetween')->andReturnSelf();
    $commissionMock->shouldReceive('get')->andReturn(collect([
        (object) ['payout_status' => 'pending',  'currency_code' => 'AUD', 'net_outstanding_cents' => 3000, 'payout_cents' => 0, 'reversal_cents' => 0],
        (object) ['payout_status' => 'pending',  'currency_code' => 'AUD', 'net_outstanding_cents' => 2000, 'payout_cents' => 0, 'reversal_cents' => 0],
        (object) ['payout_status' => 'paid',     'currency_code' => 'AUD', 'net_outstanding_cents' => 0,    'payout_cents' => 8000, 'reversal_cents' => 0],
        (object) ['payout_status' => 'reversed', 'currency_code' => 'AUD', 'net_outstanding_cents' => 0,    'payout_cents' => 0,    'reversal_cents' => 500],
    ]));

    DB::shouldReceive('table')->with('analytics.brand_metrics_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_affiliate_daily')->andReturn(emptyQueryMock());
    DB::shouldReceive('table')->with('analytics.brand_commission_daily')->andReturn($commissionMock);

    $request = Request::create('/', 'GET', ['from' => '2026-04-01', 'to' => '2026-04-19']);
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $summary = json_decode($response->getContent(), true)['commission_summary'];

    expect($summary['pending_cents'])->toBe(5000)
        ->and($summary['paid_cents'])->toBe(8000)
        ->and($summary['reversed_cents'])->toBe(500)
        ->and($summary['approved_cents'])->toBe(0);
});
