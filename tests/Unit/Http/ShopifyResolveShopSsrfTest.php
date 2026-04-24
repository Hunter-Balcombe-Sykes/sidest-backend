<?php

use App\Http\Controllers\Api\Professional\ShopifyIntegration\ShopifyIntegrationController;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('rejects AWS metadata link-local address', function () {
    $controller = new ShopifyIntegrationController(
        Mockery::mock(\App\Services\Store\BrandAccessService::class)->shouldIgnoreMissing()
    );
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('isPrivateHost');
    $method->setAccessible(true);

    expect($method->invoke($controller, '169.254.169.254'))->toBeTrue();
});

it('rejects RFC1918 private ranges', function () {
    $controller = new ShopifyIntegrationController(
        Mockery::mock(\App\Services\Store\BrandAccessService::class)->shouldIgnoreMissing()
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
        Mockery::mock(\App\Services\Store\BrandAccessService::class)->shouldIgnoreMissing()
    );
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('isPrivateHost');
    $method->setAccessible(true);

    expect($method->invoke($controller, '127.0.0.1'))->toBeTrue();
    expect($method->invoke($controller, '::1'))->toBeTrue();
});

it('allows public IPs', function () {
    $controller = new ShopifyIntegrationController(
        Mockery::mock(\App\Services\Store\BrandAccessService::class)->shouldIgnoreMissing()
    );
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('isPrivateHost');
    $method->setAccessible(true);

    expect($method->invoke($controller, '8.8.8.8'))->toBeFalse();
});
