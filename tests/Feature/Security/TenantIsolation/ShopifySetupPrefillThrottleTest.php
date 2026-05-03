<?php

use Illuminate\Support\Facades\Route;

it('applies a dedicated tight throttle to the shopify setup-prefill route', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/shopify/setup-prefill');

    expect($route)->not->toBeNull('setup-prefill route must exist');

    $middleware = $route->gatherMiddleware();

    // Must have a dedicated throttle:10,15 (10 requests per 15 minutes)
    // tighter than the group-level throttle:60,1
    $tightThrottle = collect($middleware)->contains('throttle:10,15');

    expect($tightThrottle)->toBeTrue('setup-prefill must have a dedicated throttle:10,15 to prevent token brute-force');
});
