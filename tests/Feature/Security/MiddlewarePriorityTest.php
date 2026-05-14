<?php

use App\Http\Middleware\Auth\VerifyShopifySessionToken;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

// Regression for B14 follow-up (Nightwatch issue, May 2026).
// SortedMiddleware was pulling ThrottleRequests ahead of VerifySupabaseJwt
// because SubstituteBindings (priority 9, from the api group) outranked
// ThrottleRequests (priority 6) — which dragged the throttle middleware
// ahead of the unlisted JWT verifier. The per-uid rate limiters then fired
// before `supabase_uid` was set and threw RuntimeException on every
// authenticated request.
it('orders VerifySupabaseJwt before both throttle middleware in the priority list', function () {
    // Resolve the HTTP kernel so the withMiddleware() bootstrap callback fires
    // and applies our prependToPriorityList() customization to the router.
    app(\Illuminate\Contracts\Http\Kernel::class);

    $priority = app('router')->middlewarePriority;

    $jwtIndex = array_search(VerifySupabaseJwt::class, $priority, true);
    $throttleIndex = array_search(ThrottleRequests::class, $priority, true);
    $throttleRedisIndex = array_search(ThrottleRequestsWithRedis::class, $priority, true);

    expect($jwtIndex)->not->toBeFalse('VerifySupabaseJwt must be in middlewarePriority');
    expect($throttleIndex)->not->toBeFalse('ThrottleRequests must be in middlewarePriority');
    expect($throttleRedisIndex)->not->toBeFalse('ThrottleRequestsWithRedis must be in middlewarePriority');

    expect($jwtIndex)->toBeLessThan($throttleIndex);
    expect($jwtIndex)->toBeLessThan($throttleRedisIndex);
});

it('returns 401 (not 500) on /api/me without auth — proves JWT verifier runs before rate limiter', function () {
    // If the priority sort regresses, the per-uid rate limiter callback
    // fires before VerifySupabaseJwt and throws RuntimeException → 500.
    // With the correct ordering, VerifySupabaseJwt rejects the missing
    // bearer token cleanly with 401.
    $response = $this->getJson('/api/me');

    expect($response->status())->toBe(401);
    expect($response->json('message'))->toBe('Missing Bearer token');
});

it('orders VerifyShopifySessionToken before both throttle middleware in the priority list', function () {
    // Symmetric pin to VerifySupabaseJwt. The `embedded-by-shop` limiter reads
    // `embedded_shop_domain` from request attributes — if ThrottleRequests sorts
    // ahead of the JWT verifier, the attribute is unset and the limiter previously
    // collapsed to $request->ip() (silent cross-tenant collision behind Cloudflare).
    app(\Illuminate\Contracts\Http\Kernel::class);

    $priority = app('router')->middlewarePriority;

    $sessionIndex = array_search(VerifyShopifySessionToken::class, $priority, true);
    $throttleIndex = array_search(ThrottleRequests::class, $priority, true);
    $throttleRedisIndex = array_search(ThrottleRequestsWithRedis::class, $priority, true);

    expect($sessionIndex)->not->toBeFalse('VerifyShopifySessionToken must be in middlewarePriority');
    expect($sessionIndex)->toBeLessThan($throttleIndex);
    expect($sessionIndex)->toBeLessThan($throttleRedisIndex);
});

/**
 * The rate-limiter closure captures partna.throttle.enabled BY VALUE at boot
 * time, and the local .env disables it. Re-run configureRateLimiting() so
 * the freshly registered closure captures `true` and we exercise the real
 * production branch instead of the test-env Limit::none() short-circuit.
 */
function rebindEmbeddedLimiterWithThrottleEnabled(): void
{
    config()->set('partna.throttle.enabled', true);
    $provider = app()->getProvider(\App\Providers\AppServiceProvider::class)
        ?: new \App\Providers\AppServiceProvider(app());
    $method = new \ReflectionMethod($provider, 'configureRateLimiting');
    $method->setAccessible(true);
    $method->invoke($provider);
}

it('embedded-by-shop limiter throws if embedded_shop_domain is missing — no silent IP fallback', function () {
    // Regression: the limiter used to fall back to $request->ip() when the
    // JWT-set attribute was absent. Behind Cloudflare every backend request
    // shares a small pool of edge IPs, so the IP fallback silently bucketed
    // every tenant (and every auth failure) into one shared limit. The fix
    // matches the per-uid limiter pattern: throw RuntimeException so a broken
    // middleware pin surfaces as a 500 in Nightwatch instead of cross-tenant
    // throttle collisions.
    rebindEmbeddedLimiterWithThrottleEnabled();

    $callback = \Illuminate\Support\Facades\RateLimiter::limiter('embedded-by-shop');
    expect($callback)->not->toBeNull('embedded-by-shop limiter must be registered in AppServiceProvider');

    $request = \Illuminate\Http\Request::create('/__embedded/test');

    expect(fn () => $callback($request))
        ->toThrow(\RuntimeException::class, 'embedded_shop_domain missing');
});

it('embedded-by-shop limiter keys by the JWT-supplied shop domain when present', function () {
    // Positive path: when VerifyShopifySessionToken set the attribute, the
    // limiter must key on it (prefixed with 'embedded-shop:') — not IP.
    rebindEmbeddedLimiterWithThrottleEnabled();

    $callback = \Illuminate\Support\Facades\RateLimiter::limiter('embedded-by-shop');

    $request = \Illuminate\Http\Request::create('/__embedded/test');
    $request->attributes->set('embedded_shop_domain', 'tenant-a.myshopify.com');

    $limit = $callback($request);

    expect($limit)->toBeInstanceOf(\Illuminate\Cache\RateLimiting\Limit::class)
        ->and($limit->key)->toBe('embedded-shop:tenant-a.myshopify.com')
        ->and($limit->maxAttempts)->toBe(60);
});
