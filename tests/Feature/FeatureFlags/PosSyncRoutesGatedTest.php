<?php

use Illuminate\Support\Facades\Route;

function posRouteMiddlewareFor(string $method, string $uri): array
{
    $route = collect(Route::getRoutes()->getRoutes())->first(function ($r) use ($method, $uri) {
        return in_array(strtoupper($method), $r->methods())
            && $r->uri() === ltrim($uri, '/');
    });

    expect($route)->not->toBeNull("Route [{$method} {$uri}] not registered");

    return $route->gatherMiddleware();
}

// --- Square (all under feature:square_sync) ---

it('GET api/square/status has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('GET', 'api/square/status'))
        ->toContain('feature:square_sync');
});

it('POST api/square/connect has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/square/connect'))
        ->toContain('feature:square_sync');
});

it('POST api/square/disconnect has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/square/disconnect'))
        ->toContain('feature:square_sync');
});

it('GET api/square/token has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('GET', 'api/square/token'))
        ->toContain('feature:square_sync');
});

it('POST api/square/services/sync has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/square/services/sync'))
        ->toContain('feature:square_sync');
});

it('POST api/square/services/{service}/push has feature:square_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/square/services/{service}/push'))
        ->toContain('feature:square_sync');
});

// --- Fresha (all under feature:fresha_sync) ---

it('GET api/fresha/status has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('GET', 'api/fresha/status'))
        ->toContain('feature:fresha_sync');
});

it('POST api/fresha/connect has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/fresha/connect'))
        ->toContain('feature:fresha_sync');
});

it('POST api/fresha/disconnect has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/fresha/disconnect'))
        ->toContain('feature:fresha_sync');
});

it('GET api/fresha/token has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('GET', 'api/fresha/token'))
        ->toContain('feature:fresha_sync');
});

it('POST api/fresha/services/sync has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/fresha/services/sync'))
        ->toContain('feature:fresha_sync');
});

it('POST api/fresha/services/{service}/push has feature:fresha_sync middleware', function () {
    expect(posRouteMiddlewareFor('POST', 'api/fresha/services/{service}/push'))
        ->toContain('feature:fresha_sync');
});
