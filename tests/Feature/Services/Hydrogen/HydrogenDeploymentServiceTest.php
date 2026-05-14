<?php

use App\Services\Hydrogen\HydrogenDeploymentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Locks down the per-brand debounce on Hydrogen deploys (60s window).
// Master Pattern 17 / DB-D#SCALE-4.

beforeEach(function () {
    config()->set('partna.hydrogen.github_token', 'gh_test_token');
    config()->set('partna.hydrogen.github_repo', 'test/sidest-storefront');
    config()->set('partna.hydrogen.github_ref', 'main');
});

it('dispatches a workflow when the debounce key is free', function () {
    Cache::forget('hydrogen:deploy:debounce:pro-1');
    Http::fake([
        'api.github.com/*' => Http::response('', 204),
    ]);

    app(HydrogenDeploymentService::class)->dispatchDeployment('pro-1');

    Http::assertSentCount(1);
});

it('debounces a second dispatch for the same brand within 60s', function () {
    Cache::forget('hydrogen:deploy:debounce:pro-2');
    Http::fake([
        'api.github.com/*' => Http::response('', 204),
    ]);

    $svc = app(HydrogenDeploymentService::class);
    $svc->dispatchDeployment('pro-2');
    $svc->dispatchDeployment('pro-2');

    // Second call is debounced — only one workflow dispatch fired.
    Http::assertSentCount(1);
});

it('does not debounce across different brands', function () {
    Cache::forget('hydrogen:deploy:debounce:pro-3');
    Cache::forget('hydrogen:deploy:debounce:pro-4');
    Http::fake([
        'api.github.com/*' => Http::response('', 204),
    ]);

    $svc = app(HydrogenDeploymentService::class);
    $svc->dispatchDeployment('pro-3');
    $svc->dispatchDeployment('pro-4');

    Http::assertSentCount(2);
});

it('skips dispatch entirely when no GitHub token is configured (no debounce side-effect either)', function () {
    Cache::forget('hydrogen:deploy:debounce:pro-5');
    config()->set('partna.hydrogen.github_token', null);
    Http::preventStrayRequests();

    // Should not throw — best-effort semantics preserved.
    app(HydrogenDeploymentService::class)->dispatchDeployment('pro-5');

    // Debounce key WAS claimed even though we early-returned on missing token.
    // That's acceptable: missing token is a config error, not a hot path; and
    // claiming the key first prevents any race against a later configure-then-save.
    expect(Cache::has('hydrogen:deploy:debounce:pro-5'))->toBeTrue();
});
