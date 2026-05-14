<?php

uses(Tests\TestCase::class);

use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;

it('forgets the affiliate projections cache key when invalidating', function () {
    Cache::flush();
    $proId = '11111111-1111-1111-1111-111111111111';
    $key = CacheKeyGenerator::affiliateProjections($proId);

    Cache::put($key, ['payload' => 'stale'], 600);
    expect(Cache::has($key))->toBeTrue();

    app(AnalyticsCacheService::class)->invalidateAnalytics($proId);

    expect(Cache::has($key))->toBeFalse();
});

it('forgets the affiliate projections SWR :stale companion key when invalidating', function () {
    Cache::flush();
    $proId = '11111111-1111-1111-1111-111111111111';
    $key = CacheKeyGenerator::affiliateProjections($proId).':stale';

    Cache::put($key, ['payload' => 'stale'], 600);
    expect(Cache::has($key))->toBeTrue();

    app(AnalyticsCacheService::class)->invalidateAnalytics($proId);

    expect(Cache::has($key))->toBeFalse();
});

it('forgets the embedded setup overview cache key when invalidating', function () {
    Cache::flush();
    $proId = '11111111-1111-1111-1111-111111111111';
    $key = CacheKeyGenerator::embeddedSetupOverview($proId);

    Cache::put($key, ['affiliate_count' => 5], 600);
    expect(Cache::has($key))->toBeTrue();

    app(AnalyticsCacheService::class)->invalidateAnalytics($proId);

    expect(Cache::has($key))->toBeFalse();
});

it('forgets the embedded setup overview SWR :stale companion key when invalidating', function () {
    Cache::flush();
    $proId = '11111111-1111-1111-1111-111111111111';
    $key = CacheKeyGenerator::embeddedSetupOverview($proId).':stale';

    Cache::put($key, ['affiliate_count' => 5], 600);
    expect(Cache::has($key))->toBeTrue();

    app(AnalyticsCacheService::class)->invalidateAnalytics($proId);

    expect(Cache::has($key))->toBeFalse();
});
