<?php

use App\Services\Cache\CacheKeyGenerator;

it('builds a stable, professional-scoped cache key for affiliate projections', function () {
    $key = CacheKeyGenerator::affiliateProjections('11111111-2222-3333-4444-555555555555');
    expect($key)->toBe('analytics:commerce:affiliate:projections:v1:11111111-2222-3333-4444-555555555555');
});

it('appends :wN suffix when window_days is provided', function () {
    $key = CacheKeyGenerator::affiliateProjections('11111111-2222-3333-4444-555555555555', 30);
    expect($key)->toBe('analytics:commerce:affiliate:projections:v1:11111111-2222-3333-4444-555555555555:w30');
});

it('returns the default key when window_days is null', function () {
    $key = CacheKeyGenerator::affiliateProjections('11111111-2222-3333-4444-555555555555', null);
    expect($key)->toBe('analytics:commerce:affiliate:projections:v1:11111111-2222-3333-4444-555555555555');
});
