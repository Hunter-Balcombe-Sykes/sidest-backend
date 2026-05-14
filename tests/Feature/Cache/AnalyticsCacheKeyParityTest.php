<?php

/** @phpstan-ignore-all */

use App\Services\Cache\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
});

it('invalidateAnalytics bumps the summary version token', function () {
    $professionalId = (string) Str::uuid();
    $versionKey = CacheKeyGenerator::analyticsSummaryVersion($professionalId);

    // Version starts at zero / absent.
    expect(Cache::get($versionKey))->toBeNull();

    $service = app(AnalyticsCacheService::class);
    $service->invalidateAnalytics($professionalId);

    // After first invalidation the version token must exist and be ≥ 1.
    $version = (int) Cache::get($versionKey);
    expect($version)->toBeGreaterThanOrEqual(1);
});

it('each successive invalidation increments the version token', function () {
    $professionalId = (string) Str::uuid();
    $versionKey = CacheKeyGenerator::analyticsSummaryVersion($professionalId);

    $service = app(AnalyticsCacheService::class);
    $service->invalidateAnalytics($professionalId);
    $v1 = (int) Cache::get($versionKey);

    $service->invalidateAnalytics($professionalId);
    $v2 = (int) Cache::get($versionKey);

    expect($v2)->toBe($v1 + 1);
});

it('invalidation does not affect the version token of a different professional', function () {
    $proA = (string) Str::uuid();
    $proB = (string) Str::uuid();

    $service = app(AnalyticsCacheService::class);
    $service->invalidateAnalytics($proA);

    expect(Cache::get(CacheKeyGenerator::analyticsSummaryVersion($proB)))->toBeNull();
});

it('analytics summary cache key uses YmdH format matching the controller', function () {
    $professionalId = (string) Str::uuid();
    $from = Carbon::parse('2026-03-01 08:00:00');
    $to = Carbon::parse('2026-03-29 23:00:00');

    // The key the controller builds (matching ProfessionalAnalyticsController line 78-82):
    $version = (int) Cache::get(CacheKeyGenerator::analyticsSummaryVersion($professionalId), 0);
    $controllerKey = CacheKeyGenerator::analyticsSummary(
        $professionalId,
        $from->format('YmdH'),
        $to->format('YmdH')
    ).':day'.":v{$version}";

    // Seed that key.
    Cache::put($controllerKey, ['seeded' => true], now()->addMinutes(5));

    // Confirm it is present.
    expect(Cache::get($controllerKey))->toBe(['seeded' => true]);

    // After invalidation, the version bumps so this key is unreachable.
    $service = app(AnalyticsCacheService::class);
    $service->invalidateAnalytics($professionalId);

    $newVersion = (int) Cache::get(CacheKeyGenerator::analyticsSummaryVersion($professionalId), 0);
    $newKey = CacheKeyGenerator::analyticsSummary(
        $professionalId,
        $from->format('YmdH'),
        $to->format('YmdH')
    ).':day'.":v{$newVersion}";

    expect($newKey)->not->toBe($controllerKey, 'Version bump should change the key');
    expect(Cache::get($newKey))->toBeNull('New versioned key should be empty after invalidation');
});
