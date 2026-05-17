<?php

use App\Http\Controllers\Api\Professional\Brand\ShopifyIntegrationController;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function makeShopifyController(): ShopifyIntegrationController
{
    return new ShopifyIntegrationController(
        Mockery::mock(\App\Services\Shopify\ShopifyDisconnectService::class)->shouldIgnoreMissing()
    );
}

function invokePrivate(ShopifyIntegrationController $controller, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionClass($controller);
    $m = $reflection->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke($controller, ...$args);
}

it('rejects AWS metadata link-local address', function () {
    $controller = new ShopifyIntegrationController(
        Mockery::mock(\App\Services\Shopify\ShopifyDisconnectService::class)->shouldIgnoreMissing()
    );
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('isPrivateHost');
    $method->setAccessible(true);

    expect($method->invoke($controller, '169.254.169.254'))->toBeTrue();
});

it('rejects RFC1918 private ranges', function () {
    $controller = new ShopifyIntegrationController(
        Mockery::mock(\App\Services\Shopify\ShopifyDisconnectService::class)->shouldIgnoreMissing()
    );
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('isPrivateHost');
    $method->setAccessible(true);

    expect($method->invoke($controller, '10.0.0.1'))->toBeTrue();
    expect($method->invoke($controller, '192.168.1.1'))->toBeTrue();
    expect($method->invoke($controller, '172.16.0.1'))->toBeTrue();
});

it('rejects loopback addresses', function () {
    $controller = new ShopifyIntegrationController(
        Mockery::mock(\App\Services\Shopify\ShopifyDisconnectService::class)->shouldIgnoreMissing()
    );
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('isPrivateHost');
    $method->setAccessible(true);

    expect($method->invoke($controller, '127.0.0.1'))->toBeTrue();
    expect($method->invoke($controller, '::1'))->toBeTrue();
});

it('allows public IPs', function () {
    $controller = new ShopifyIntegrationController(
        Mockery::mock(\App\Services\Shopify\ShopifyDisconnectService::class)->shouldIgnoreMissing()
    );
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('isPrivateHost');
    $method->setAccessible(true);

    expect($method->invoke($controller, '8.8.8.8'))->toBeFalse();
});

it('resolveSafeIps returns null for blocked private IPs', function () {
    expect(invokePrivate(makeShopifyController(), 'resolveSafeIps', '127.0.0.1'))->toBeNull();
    expect(invokePrivate(makeShopifyController(), 'resolveSafeIps', '169.254.169.254'))->toBeNull();
});

it('resolveSafeIps returns empty array for public IP literals (no pinning needed)', function () {
    expect(invokePrivate(makeShopifyController(), 'resolveSafeIps', '8.8.8.8'))->toBe([]);
});

it('discoverShopifyHandle returns the structured Shopify.shop value', function () {
    Http::fake([
        'https://8.8.8.8/' => Http::response(
            '<html><head><script>var Shopify = {}; Shopify.shop = "real-store.myshopify.com";</script></head></html>',
            200
        ),
    ]);

    $result = invokePrivate(makeShopifyController(), 'discoverShopifyHandle', '8.8.8.8');

    expect($result)->toBe('real-store.myshopify.com');
});

it('discoverShopifyHandle ignores bare myshopify mentions in body text (no UGC fallback)', function () {
    // Body has no structured Shopify.shop / "shop": tokens — only a bare mention
    // in a footer / blog paragraph that an attacker could plant on a custom domain.
    // Pre-fix code would have matched the third regex and returned `attacker-controlled.myshopify.com`.
    Http::fake([
        'https://8.8.8.8/' => Http::response(
            '<html><body><p>Powered by attacker-controlled.myshopify.com</p></body></html>',
            200
        ),
    ]);

    $result = invokePrivate(makeShopifyController(), 'discoverShopifyHandle', '8.8.8.8');

    expect($result)->toBeNull();
});

it('discoverShopifyHandle still matches "shop": JSON-style token', function () {
    Http::fake([
        'https://8.8.8.8/' => Http::response(
            '<html><script>window.bootstrap = {"shop":"json-store.myshopify.com"};</script></html>',
            200
        ),
    ]);

    $result = invokePrivate(makeShopifyController(), 'discoverShopifyHandle', '8.8.8.8');

    expect($result)->toBe('json-store.myshopify.com');
});

it('discoverShopifyHandle short-circuits on a blocked private host without making an HTTP call', function () {
    Http::fake();

    $result = invokePrivate(makeShopifyController(), 'discoverShopifyHandle', '169.254.169.254');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});
