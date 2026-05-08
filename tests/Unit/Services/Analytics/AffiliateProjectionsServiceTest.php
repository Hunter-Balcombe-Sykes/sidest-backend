<?php

uses(Tests\TestCase::class);

use App\Models\Core\Professional\Professional;
use App\Services\Analytics\AffiliateProjectionsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->service = app(AffiliateProjectionsService::class);
    // forceFill is needed because 'id' is not in $fillable on Professional.
    $this->professional = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'timezone' => 'America/New_York',
    ]);
});

it('returns insufficient_data when the affiliate has < 14 days of history', function () {
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    // Use the same timezone as the professional so diffInDays matches expectations regardless of when tests run.
    $rollupMock->shouldReceive('value')->with('day')->andReturn(\Carbon\CarbonImmutable::now('America/New_York')->subDays(5)->toDateString());
    $rollupMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')
        ->with('commerce.brand_affiliate_rollup')
        ->andReturn($rollupMock);

    $result = $this->service->build($this->professional);

    expect($result['status'])->toBe('insufficient_data');
    expect($result['data_history_days'])->toBe(5);
    expect($result['by_currency'])->toBe([]);
});

it('computes run-rate and annual projections from the window rollup rows', function () {
    $today = \Carbon\CarbonImmutable::now('America/New_York')->startOfDay();
    $earliest = $today->subDays(60)->toDateString();
    $windowFrom = $today->subDays(29)->toDateString();
    $windowTo = $today->subDay()->toDateString();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'orderByRaw', 'groupBy', 'fromRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($earliest);
    // 30 days × 100_000 net cents/day = 3_000_000 in window. 30 orders.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 2,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));
    // Prior-window fetch returns no data; momentum pct_change will be null.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect());
    // YTD and best-month fetches return empty (not tested here).
    $rollupMock->shouldReceive('get')->once()->andReturn(collect());
    $rollupMock->shouldReceive('get')->once()->andReturn(collect());

    DB::shouldReceive('table')
        ->with('commerce.brand_affiliate_rollup')
        ->andReturn($rollupMock);

    $pro = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'timezone' => 'America/New_York',
    ]);
    $result = $this->service->build($pro);

    expect($result['status'])->toBe('ok');
    expect($result['window']['days'])->toBe(30);
    expect($result['by_currency'][0]['currency_code'])->toBe('USD');
    expect($result['by_currency'][0]['run_rate']['commission_cents_per_day'])->toBe(100000);
    expect($result['by_currency'][0]['projections']['annual_commission_cents'])->toBe(36500000); // 100k * 365
    expect($result['by_currency'][0]['projections']['annual_orders'])->toBe(365); // (30/30)*365
});

it('computes momentum as pct change from prior window run-rate', function () {
    $today = \Carbon\CarbonImmutable::now('America/New_York')->startOfDay();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'orderByRaw', 'groupBy', 'fromRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    // 31 days history → strict > 30 → windowDays = 30, so run-rate math stays clean.
    $rollupMock->shouldReceive('value')->with('day')->andReturn($today->subDays(31)->toDateString());

    // Two get() calls: window then prior-window, in order.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 2,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));
    // Prior window: 50% lower run-rate (50k/day = 1.5M / 30 days).
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) ['currency_code' => 'USD', 'prior_net_cents' => 1_500_000],
    ]));
    // YTD and best-month fetches return empty (not tested here).
    $rollupMock->shouldReceive('get')->once()->andReturn(collect());
    $rollupMock->shouldReceive('get')->once()->andReturn(collect());

    DB::shouldReceive('table')
        ->with('commerce.brand_affiliate_rollup')
        ->andReturn($rollupMock);

    $pro = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'timezone' => 'America/New_York',
    ]);
    $result = $this->service->build($pro);

    expect($result['by_currency'][0]['momentum']['pct_change_vs_prior_window'])->toBe(1.0); // 100k vs 50k = +100%
    expect($result['by_currency'][0]['momentum']['prior_run_rate_cents_per_day'])->toBe(50000);
});

it('returns null pct_change when prior-window run-rate is zero (avoid div-by-zero)', function () {
    $today = \Carbon\CarbonImmutable::now('America/New_York')->startOfDay();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'orderByRaw', 'groupBy', 'fromRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($today->subDays(31)->toDateString());

    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 2,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));
    $rollupMock->shouldReceive('get')->once()->andReturn(collect()); // no prior data
    $rollupMock->shouldReceive('get')->once()->andReturn(collect()); // ytd (empty)
    $rollupMock->shouldReceive('get')->once()->andReturn(collect()); // best month (empty)

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    $pro = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'timezone' => 'America/New_York',
    ]);
    $result = $this->service->build($pro);

    expect($result['by_currency'][0]['momentum']['pct_change_vs_prior_window'])->toBeNull();
    expect($result['by_currency'][0]['momentum']['prior_run_rate_cents_per_day'])->toBe(0);
});

it('returns high confidence when history >= 90d AND CV < 0.5', function () {
    expectConfidence(historyDays: 95, dailyValues: array_fill(0, 90, 100000), expected: 'high');
});

it('returns medium confidence when history >= 30d AND CV < 1.0 but high not met', function () {
    // 30 days, mostly 100k with one 200k spike → CV ≈ 0.18 → medium (< 90 day history blocks high)
    $values = array_merge(array_fill(0, 29, 100000), [200000]);
    expectConfidence(historyDays: 35, dailyValues: $values, expected: 'medium');
});

it('returns low confidence when CV is high', function () {
    // Volatile: lots of zeros and a few big days → CV > 1.0
    $values = array_merge(array_fill(0, 27, 0), [500000, 500000, 500000]);
    expectConfidence(historyDays: 95, dailyValues: $values, expected: 'low');
});

it('computes YTD totals, best month, and year-end projection per currency', function () {
    $now = \Carbon\CarbonImmutable::now('America/New_York');
    $today = $now->startOfDay();

    // Use 31-day history (consistent with momentum/confidence tests) so windowDays = 30
    // and run_rate = 3_000_000 / 30 = 100_000 cents/day.
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'orderByRaw', 'groupBy', 'fromRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($today->subDays(31)->toDateString());

    // 1) Window aggregate.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 2,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));
    // 2) Prior window aggregate (empty — no momentum data).
    $rollupMock->shouldReceive('get')->once()->andReturn(collect());
    // 3) YTD aggregate.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'ytd_net_cents' => 6_120_000,
            'ytd_orders' => 178,
        ],
    ]));
    // 4) Best month per currency.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'best_month' => '2026-03',
            'best_month_net_cents' => 1_840_000,
        ],
    ]));

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    $pro = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'timezone' => 'America/New_York',
    ]);
    $result = $this->service->build($pro);

    $usd = $result['by_currency'][0];
    expect($usd['ytd']['commission_cents'])->toBe(6120000);
    expect($usd['ytd']['orders_count'])->toBe(178);
    expect($usd['ytd']['best_month'])->toBe('2026-03');
    expect($usd['ytd']['best_month_commission_cents'])->toBe(1840000);

    // year_end = ytd + (run_rate * days_remaining_in_year)
    // run_rate = 100_000 cents/day (3M / 30-day window)
    $daysRemaining = (int) $today->diffInDays($now->endOfYear()->startOfDay());
    expect($usd['projections']['year_end_commission_cents'])
        ->toBe(6120000 + 100000 * $daysRemaining);
});

// Helper: wires up DB mocks for a single-currency window with given daily values.
// Reproduces the mock shape used by other tests, parameterized for confidence checks.
function expectConfidence(int $historyDays, array $dailyValues, string $expected): void
{
    $today = \Carbon\CarbonImmutable::now('America/New_York')->startOfDay();
    $windowDays = count($dailyValues);
    $netCents = array_sum($dailyValues);

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'orderByRaw', 'groupBy', 'fromRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($today->subDays($historyDays)->toDateString());
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => $netCents,
            'window_orders' => $windowDays,
            'earning_days' => count(array_filter($dailyValues, fn ($v) => $v > 0)),
            'brand_count' => 1,
            'daily_values_json' => json_encode($dailyValues),
        ],
    ]));
    $rollupMock->shouldReceive('get')->once()->andReturn(collect()); // no prior
    $rollupMock->shouldReceive('get')->once()->andReturn(collect()); // ytd (empty)
    $rollupMock->shouldReceive('get')->once()->andReturn(collect()); // best month (empty)

    \Illuminate\Support\Facades\DB::shouldReceive('table')
        ->with('commerce.brand_affiliate_rollup')
        ->andReturn($rollupMock);

    $service = app(\App\Services\Analytics\AffiliateProjectionsService::class);
    $pro = (new \App\Models\Core\Professional\Professional)->forceFill([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'timezone' => 'America/New_York',
    ]);
    $result = $service->build($pro);

    expect($result['by_currency'][0]['projections']['confidence'])->toBe($expected);
}
