<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('preserves throttle response status for api routes', function () {
    $limiter = 'api-exception-'.Str::random(12);
    $path = '/api/_test/throttle-'.Str::random(12);
    $limitKey = 'key-'.Str::random(12);

    RateLimiter::for($limiter, function (Request $request) use ($limitKey) {
        return Limit::perMinute(1)
            ->by($limitKey)
            ->response(function () {
                return response()->json([
                    'message' => 'Too many requests. Please try again later.',
                ], 429);
            });
    });

    Route::middleware("throttle:{$limiter}")
        ->get($path, fn () => response()->json(['ok' => true]));

    $this->getJson($path)->assertOk();
    $this->getJson($path)
        ->assertStatus(429)
        ->assertJson([
            'message' => 'Too many requests. Please try again later.',
        ]);
});
