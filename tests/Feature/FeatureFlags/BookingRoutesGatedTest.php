<?php

use Illuminate\Support\Facades\Route;

function routeMiddlewareFor(string $method, string $uri): array
{
    $route = collect(Route::getRoutes()->getRoutes())->first(function ($r) use ($method, $uri) {
        return in_array(strtoupper($method), $r->methods())
            && $r->uri() === ltrim($uri, '/');
    });

    expect($route)->not->toBeNull("Route [{$method} {$uri}] not registered");

    return $route->gatherMiddleware();
}

it('PATCH api/booking/settings has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('PATCH', 'api/booking/settings'))
        ->toContain('feature:smart_booking');
});

it('GET api/booking/my-analytics/overview has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('GET', 'api/booking/my-analytics/overview'))
        ->toContain('feature:smart_booking');
});

it('GET api/public/booking/config-by-slug has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('GET', 'api/public/booking/config-by-slug'))
        ->toContain('feature:smart_booking');
});

it('GET api/public/booking/services-by-slug has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('GET', 'api/public/booking/services-by-slug'))
        ->toContain('feature:smart_booking');
});

it('POST api/public/booking/availability-by-slug has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('POST', 'api/public/booking/availability-by-slug'))
        ->toContain('feature:smart_booking');
});

it('POST api/public/booking/checkout-by-slug has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('POST', 'api/public/booking/checkout-by-slug'))
        ->toContain('feature:smart_booking');
});

it('GET api/public/booking/config has feature:smart_booking middleware', function () {
    expect(routeMiddlewareFor('GET', 'api/public/booking/config'))
        ->toContain('feature:smart_booking');
});
