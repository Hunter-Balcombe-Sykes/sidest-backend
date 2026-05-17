<?php

use App\Http\Controllers\Api\Internal\EmbeddedSetupController;
use App\Http\Controllers\Api\Professional\Store\BrandStoreSettingsController;
use App\Models\Brand\BrandStoreSettings;
use App\Services\Professional\Brand\BrandStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// SEC-5: defence-in-depth host-suffix guard for outbound storefront probes.
//
// Three private call sites construct an outbound URL by string-concatenating a
// DB-sourced subdomain onto `partna.public_domain` and call Http::get(). The
// subdomain column is constrained today, but if it ever accepts arbitrary
// input (or is breached), an attacker can craft a value containing a `#` or
// `/` so that parse_url() reads the host as something *outside* partna.au —
// e.g. the cloud-metadata IP at 169.254.169.254. The guard must reject any
// resolved host that does not end in `.<public_domain>` before the GET fires.
//
// These tests use reflection to invoke each private method directly so the
// contract is asserted at the unit boundary, not buried under wizard setup.

beforeEach(function () {
    Cache::flush();
    config(['partna.public_domain' => 'partna.test']);
});

/**
 * @return mixed
 */
function invokePrivate(object $instance, string $method, mixed ...$args)
{
    $ref = (new ReflectionClass($instance))->getMethod($method);
    $ref->setAccessible(true);

    return $ref->invoke($instance, ...$args);
}

// ── EmbeddedSetupController::checkStorefrontStatus ───────────────────────────

it('EmbeddedSetupController allows subdomains under the configured public domain', function () {
    Http::fake([
        'https://shop.partna.test*' => Http::response('', 200),
    ]);

    $controller = app(EmbeddedSetupController::class);
    $result = invokePrivate($controller, 'checkStorefrontStatus', 'shop');

    expect($result)->toBe('live');
    Http::assertSent(fn ($r) => $r->url() === 'https://shop.partna.test');
});

it('EmbeddedSetupController refuses a subdomain that escapes via fragment to another host', function (string $maliciousSubdomain) {
    Http::fake(); // any outbound call would 200 by default; we'll assert none were sent.

    $controller = app(EmbeddedSetupController::class);
    $result = invokePrivate($controller, 'checkStorefrontStatus', $maliciousSubdomain);

    expect($result)->toBe('unreachable');
    Http::assertNothingSent();
})->with([
    'fragment escape to evil host' => 'evil.com#',
    'path escape to evil host' => 'evil.com/',
    'raw metadata IP via fragment' => '169.254.169.254#',
    'basic-auth host escape' => '@evil.com#',
    'query escape to evil host' => 'evil.com?',
]);

// ── BrandStoreSettingsController::checkStorefrontStatus ──────────────────────

it('BrandStoreSettingsController allows subdomains under the configured public domain', function () {
    Http::fake([
        'https://shop.partna.test*' => Http::response('', 200),
    ]);

    $controller = app(BrandStoreSettingsController::class);
    $result = invokePrivate($controller, 'checkStorefrontStatus', 'shop');

    expect($result)->toBe('live');
    Http::assertSent(fn ($r) => $r->url() === 'https://shop.partna.test');
});

it('BrandStoreSettingsController refuses a subdomain that escapes via fragment to another host', function (string $maliciousSubdomain) {
    Http::fake();

    $controller = app(BrandStoreSettingsController::class);
    $result = invokePrivate($controller, 'checkStorefrontStatus', $maliciousSubdomain);

    expect($result)->toBe('unreachable');
    Http::assertNothingSent();
})->with([
    'fragment escape to evil host' => 'evil.com#',
    'path escape to evil host' => 'evil.com/',
    'raw metadata IP via fragment' => '169.254.169.254#',
    'basic-auth host escape' => '@evil.com#',
    'query escape to evil host' => 'evil.com?',
]);

// ── BrandStatusService::isStorefrontReachable ────────────────────────────────

it('BrandStatusService refuses a subdomain that escapes via fragment to another host', function (string $maliciousSubdomain) {
    Http::fake();

    $service = app(BrandStatusService::class);
    $settings = new BrandStoreSettings; // any non-null instance — the body only null-checks.
    $result = invokePrivate($service, 'isStorefrontReachable', $settings, $maliciousSubdomain);

    expect($result)->toBeFalse();
    Http::assertNothingSent();
})->with([
    'fragment escape to evil host' => 'evil.com#',
    'path escape to evil host' => 'evil.com/',
    'raw metadata IP via fragment' => '169.254.169.254#',
    'basic-auth host escape' => '@evil.com#',
    'query escape to evil host' => 'evil.com?',
]);

it('BrandStatusService allows subdomains under the configured public domain', function () {
    Http::fake([
        'https://shop.partna.test*' => Http::response('', 200),
    ]);

    $service = app(BrandStatusService::class);
    $settings = new BrandStoreSettings;
    $result = invokePrivate($service, 'isStorefrontReachable', $settings, 'shop');

    expect($result)->toBeTrue();
    Http::assertSent(fn ($r) => $r->url() === 'https://shop.partna.test');
});
