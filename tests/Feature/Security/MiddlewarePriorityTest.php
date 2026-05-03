<?php

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
