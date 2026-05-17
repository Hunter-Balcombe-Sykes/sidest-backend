<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Affiliate-only Stripe Route Guard Coverage
|--------------------------------------------------------------------------
| The /stripe/balance and /stripe/payouts/upcoming endpoints return Stripe
| Connect data that only makes sense for affiliate professionals. The role
| restriction must be enforced by the `affiliate.only` middleware (see
| EnsureAffiliateAccount) — NOT by inline `professional_type === 'brand'`
| checks in the controller, which are exclusion-based and silently pass
| through nulls / unknown types.
|
| Matches the precedent set by BrandRoleGuardTest.php and the audit-fix
| pattern that moved BrandPartnerController's role check to middleware.
*/

function affiliateStripeRouteMiddleware(string $method, string $uri): array
{
    $route = collect(Route::getRoutes()->getRoutes())->first(function ($r) use ($method, $uri) {
        return in_array(strtoupper($method), $r->methods())
            && $r->uri() === ltrim($uri, '/');
    });

    expect($route)->not->toBeNull("Route [{$method} {$uri}] not registered");

    return $route->gatherMiddleware();
}

it('GET api/stripe/balance is gated by the affiliate.only middleware', function () {
    expect(affiliateStripeRouteMiddleware('GET', 'api/stripe/balance'))
        ->toContain('affiliate.only');
});

it('GET api/stripe/payouts/upcoming is gated by the affiliate.only middleware', function () {
    expect(affiliateStripeRouteMiddleware('GET', 'api/stripe/payouts/upcoming'))
        ->toContain('affiliate.only');
});
