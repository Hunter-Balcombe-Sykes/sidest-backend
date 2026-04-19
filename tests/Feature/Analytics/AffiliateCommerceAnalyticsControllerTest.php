<?php

use App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->controller = new AffiliateCommerceAnalyticsController();
    $this->professional = new Professional(['id' => (string) Str::uuid(), 'timezone' => 'UTC']);
    Cache::flush();
});

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

    $request = Request::create('/', 'GET');
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->overview($request);
    $data = json_decode($response->getContent(), true);

    expect($data['range']['from'])->toBe(now()->subDays(29)->toDateString())
        ->and($data['range']['to'])->toBe(now()->toDateString());
});

it('returns timeseries from daily rows', function () {

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
