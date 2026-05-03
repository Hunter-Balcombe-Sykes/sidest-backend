<?php

use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;

it('exposes three launch feature flags via config', function () {
    expect(config('sidest.features'))
        ->toBeArray()
        ->toHaveKeys(['smart_booking', 'square_sync', 'fresha_sync']);
});

it('defaults all three launch feature flags to false', function () {
    expect(config('sidest.features.smart_booking'))->toBeFalse();
    expect(config('sidest.features.square_sync'))->toBeFalse();
    expect(config('sidest.features.fresha_sync'))->toBeFalse();
});

it('returns 503 when the named feature flag is off', function () {
    config()->set('sidest.features.smart_booking', false);

    Route::middleware('feature:smart_booking')
        ->get('/__test/feature-gate', fn () => response()->json(['ok' => true]));

    get('/__test/feature-gate')
        ->assertStatus(503)
        ->assertJson(['message' => 'Feature not available']);
});

it('passes through when the named feature flag is on', function () {
    config()->set('sidest.features.smart_booking', true);

    Route::middleware('feature:smart_booking')
        ->get('/__test/feature-gate-on', fn () => response()->json(['ok' => true]));

    get('/__test/feature-gate-on')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('returns 503 for unknown flag keys (fail closed)', function () {
    Route::middleware('feature:nonexistent_flag')
        ->get('/__test/feature-gate-unknown', fn () => response()->json(['ok' => true]));

    get('/__test/feature-gate-unknown')->assertStatus(503);
});
