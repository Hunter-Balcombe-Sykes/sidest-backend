<?php

use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
});

it('bumping the version-key invalidates every windowed affiliate cache variant', function () {
    $professionalId = (string) Str::uuid();

    $k1 = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, '2026-01-01', '2026-01-31');
    $k2 = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, '2026-02-01', '2026-02-28');
    Cache::put($k1, ['snapshot' => 1], 60);
    Cache::put($k2, ['snapshot' => 2], 60);

    expect(Cache::has($k1))->toBeTrue();
    expect(Cache::has($k2))->toBeTrue();

    app(AnalyticsCacheService::class)->bumpAnalyticsVersion($professionalId);

    // After bumping, re-deriving the keys gives new keys (version+1).
    $newK1 = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, '2026-01-01', '2026-01-31');
    $newK2 = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, '2026-02-01', '2026-02-28');

    expect($newK1)->not->toBe($k1);
    expect($newK2)->not->toBe($k2);
    expect(Cache::has($newK1))->toBeFalse();
    expect(Cache::has($newK2))->toBeFalse();
});

it('bumping the version-key invalidates every windowed brand cache variant', function () {
    $professionalId = (string) Str::uuid();

    $k1 = CacheKeyGenerator::brandCommerceAnalytics($professionalId, '2026-01-01', '2026-01-31');
    $k2 = CacheKeyGenerator::brandCommerceAnalytics($professionalId, '2026-03-01', '2026-03-31');
    Cache::put($k1, ['snapshot' => 10], 60);
    Cache::put($k2, ['snapshot' => 20], 60);

    expect(Cache::has($k1))->toBeTrue();
    expect(Cache::has($k2))->toBeTrue();

    app(AnalyticsCacheService::class)->bumpAnalyticsVersion($professionalId);

    $newK1 = CacheKeyGenerator::brandCommerceAnalytics($professionalId, '2026-01-01', '2026-01-31');
    $newK2 = CacheKeyGenerator::brandCommerceAnalytics($professionalId, '2026-03-01', '2026-03-31');

    expect($newK1)->not->toBe($k1);
    expect($newK2)->not->toBe($k2);
    expect(Cache::has($newK1))->toBeFalse();
    expect(Cache::has($newK2))->toBeFalse();
});

it('bumpAnalyticsVersion does not affect windowed keys of a different professional', function () {
    $proA = (string) Str::uuid();
    $proB = (string) Str::uuid();

    $keyB = CacheKeyGenerator::affiliateCommerceAnalytics($proB, '2026-01-01', '2026-01-31');
    Cache::put($keyB, ['snapshot' => 99], 60);

    app(AnalyticsCacheService::class)->bumpAnalyticsVersion($proA);

    // proB's key should use the same version (still 0) — unchanged.
    $keyBAfter = CacheKeyGenerator::affiliateCommerceAnalytics($proB, '2026-01-01', '2026-01-31');
    expect($keyBAfter)->toBe($keyB);
    expect(Cache::get($keyBAfter))->toBe(['snapshot' => 99]);
});

it('successive bumps continue incrementing the embedded version', function () {
    $professionalId = (string) Str::uuid();

    $k0 = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, '2026-01-01', '2026-01-31');

    app(AnalyticsCacheService::class)->bumpAnalyticsVersion($professionalId);
    $k1 = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, '2026-01-01', '2026-01-31');

    app(AnalyticsCacheService::class)->bumpAnalyticsVersion($professionalId);
    $k2 = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, '2026-01-01', '2026-01-31');

    expect($k0)->not->toBe($k1);
    expect($k1)->not->toBe($k2);
    expect($k0)->not->toBe($k2);
});
