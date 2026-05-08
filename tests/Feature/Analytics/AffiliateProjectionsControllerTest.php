<?php

use App\Http\Controllers\Api\Professional\Analytics\AffiliateProjectionsController;
use App\Http\Requests\Professional\Analytics\AffiliateProjectionsRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->controller = app(AffiliateProjectionsController::class);
    $this->professional = (new Professional)->forceFill([
        'id' => (string) Str::uuid(),
        'timezone' => 'UTC',
    ]);
    Cache::flush();
});

/**
 * Builds an AffiliateProjectionsRequest with the professional attached.
 * Calling this from each test keeps the controller invocation pattern uniform.
 */
function makeProjectionsRequest(Professional $pro, string $url = '/api/professional/affiliate/projections'): AffiliateProjectionsRequest
{
    $request = AffiliateProjectionsRequest::create($url, 'GET');
    $request->attributes->set('professional', $pro);

    return $request;
}

it('returns insufficient_data when the affiliate has no rollup history', function () {
    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy', 'fromRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn(null); // no history at all
    $rollupMock->shouldReceive('get')->andReturn(collect());

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    // Bypass Redis lock with array-driver closure passthrough
    $this->mock(\App\Services\Cache\CacheLockService::class, function ($m) {
        $m->shouldReceive('rememberLocked')
            ->andReturnUsing(fn ($key, $ttl, $cb) => $cb());
    });

    $response = $this->controller->show(makeProjectionsRequest($this->professional));
    $body = json_decode($response->getContent(), true);

    expect($body['status'])->toBe('insufficient_data');
    expect($body['data_history_days'])->toBe(0);
    expect($body['by_currency'])->toBe([]);
});

it('returns ok with full projections when the affiliate has 31+ days of stable history', function () {
    $today = \Carbon\CarbonImmutable::now('UTC')->startOfDay();
    $earliest = $today->subDays(31)->toDateString();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'orderByRaw', 'groupBy', 'fromRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($earliest);

    // Window: 30 days × 100k cents = 3_000_000 net, 30 orders.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 3,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));
    // Prior window: same rate (momentum = 0).
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) ['currency_code' => 'USD', 'prior_net_cents' => 3_000_000],
    ]));
    // YTD.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) ['currency_code' => 'USD', 'ytd_net_cents' => 12_000_000, 'ytd_orders' => 200],
    ]));
    // Best month.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'best_month' => $today->subMonth()->format('Y-m'),
            'best_month_net_cents' => 3_500_000,
        ],
    ]));

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    // Bypass Redis lock with array-driver closure passthrough
    $this->mock(\App\Services\Cache\CacheLockService::class, function ($m) {
        $m->shouldReceive('rememberLocked')
            ->andReturnUsing(fn ($key, $ttl, $cb) => $cb());
    });

    $response = $this->controller->show(makeProjectionsRequest($this->professional));
    $body = json_decode($response->getContent(), true);

    expect($body['status'])->toBe('ok');
    expect($body['window']['days'])->toBe(30);
    expect($body['by_currency'])->toHaveCount(1);

    $usd = $body['by_currency'][0];
    expect($usd['currency_code'])->toBe('USD');
    expect($usd['run_rate']['commission_cents_per_day'])->toBe(100000);
    expect($usd['projections']['annual_commission_cents'])->toBe(36500000); // 100k * 365
    // PHP JSON-encodes 0.0 as integer 0; toEqual() allows type-coerced match.
    expect($usd['momentum']['pct_change_vs_prior_window'])->toEqual(0.0);
    expect($usd['ytd']['commission_cents'])->toBe(12000000);
});

it('throws AuthorizationException when policy denies (defense-in-depth check)', function () {
    // Gate::before() fires before policy resolution and short-circuits the check.
    // Gate::define() cannot override a registered policy when a model is passed
    // (Laravel's resolveAuthCallback checks policies first). Gate::before() is the
    // correct intercept — returning false from it denies any ability unconditionally.
    \Illuminate\Support\Facades\Gate::before(function ($pro, string $ability) {
        if ($ability === 'viewProjections') {
            return false; // explicit deny, triggers AuthorizationException
        }

        return null; // pass through all other abilities
    });

    $this->controller->show(makeProjectionsRequest($this->professional));
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);

it('invalidates the projections cache when AnalyticsCacheService::invalidateAnalytics is called', function () {
    $proId = (string) $this->professional->id;
    $cacheKey = CacheKeyGenerator::affiliateProjections($proId);

    Cache::put($cacheKey, ['cached' => true], 600);
    Cache::put($cacheKey.':stale', ['stale' => true], 6000);

    expect(Cache::has($cacheKey))->toBeTrue();
    expect(Cache::has($cacheKey.':stale'))->toBeTrue();

    app(AnalyticsCacheService::class)->invalidateAnalytics($proId);

    expect(Cache::has($cacheKey))->toBeFalse();
    expect(Cache::has($cacheKey.':stale'))->toBeFalse();
});

it('honors an explicit window_days=30 override even when 60+ days of history is available', function () {
    $today = \Carbon\CarbonImmutable::now('UTC')->startOfDay();
    // 61 days of history → adaptive default would pick 60-day window, but override forces 30.
    $earliest = $today->subDays(61)->toDateString();

    $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'orderByRaw', 'groupBy', 'fromRaw'] as $m) {
        $rollupMock->shouldReceive($m)->andReturnSelf();
    }
    $rollupMock->shouldReceive('value')->with('day')->andReturn($earliest);
    // Current 30-day window aggregates.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) [
            'currency_code' => 'USD',
            'window_net_cents' => 3_000_000,
            'window_orders' => 30,
            'earning_days' => 25,
            'brand_count' => 1,
            'daily_values_json' => json_encode(array_fill(0, 30, 100000)),
        ],
    ]));
    // Prior window aggregates.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) ['currency_code' => 'USD', 'prior_net_cents' => 3_000_000],
    ]));
    // YTD aggregates.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) ['currency_code' => 'USD', 'ytd_net_cents' => 12_000_000, 'ytd_orders' => 200],
    ]));
    // Best month aggregates.
    $rollupMock->shouldReceive('get')->once()->andReturn(collect([
        (object) ['currency_code' => 'USD', 'best_month' => $today->subMonth()->format('Y-m'), 'best_month_net_cents' => 3_500_000],
    ]));

    DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

    // Bypass Redis lock with array-driver closure passthrough
    $this->mock(\App\Services\Cache\CacheLockService::class, function ($m) {
        $m->shouldReceive('rememberLocked')
            ->andReturnUsing(fn ($key, $ttl, $cb) => $cb());
    });

    $request = AffiliateProjectionsRequest::create('/api/professional/affiliate/projections?window_days=30', 'GET');
    $request->attributes->set('professional', $this->professional);

    $response = $this->controller->show($request);
    $body = json_decode($response->getContent(), true);

    expect($body['window']['days'])->toBe(30);
});

it('forgets all window-variant projections cache keys on invalidate', function () {
    $proId = (string) $this->professional->id;

    foreach ([null, 14, 30, 60, 90] as $w) {
        $key = CacheKeyGenerator::affiliateProjections($proId, $w);
        Cache::put($key, ['cached' => true], 600);
        Cache::put($key.':stale', ['stale' => true], 6000);
    }

    app(AnalyticsCacheService::class)->invalidateAnalytics($proId);

    foreach ([null, 14, 30, 60, 90] as $w) {
        $key = CacheKeyGenerator::affiliateProjections($proId, $w);
        expect(Cache::has($key))->toBeFalse();
        expect(Cache::has($key.':stale'))->toBeFalse();
    }
});
