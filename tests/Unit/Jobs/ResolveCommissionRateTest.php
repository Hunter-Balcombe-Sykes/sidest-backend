<?php

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Retail\BrandStoreSettings;

uses(\Tests\TestCase::class)->in(__FILE__);

// Unit tests for ProcessShopifyOrderWebhookJob::resolveCommissionRate().
// Uses Reflection to reach the private method so we don't need the full
// Shopify webhook pipeline (which requires pgsql ON CONFLICT WHERE).

function makeResolveRateJob(): ProcessShopifyOrderWebhookJob
{
    return new ProcessShopifyOrderWebhookJob(
        brandProfessionalId: 'brand-1',
        orderPayload: [],
        shopifyEventId: 'ev-1',
    );
}

function callResolveCommissionRate(
    ProcessShopifyOrderWebhookJob $job,
    string $productGid,
    array $overrideMap,
    ?object $brandSettings,
    float $platformDefault,
): array {
    $method = new ReflectionMethod($job, 'resolveCommissionRate');
    $method->setAccessible(true);

    return $method->invoke($job, $productGid, $overrideMap, $brandSettings, $platformDefault);
}

it('returns metafield_override when the override is within bounds', function () {
    $job = makeResolveRateJob();
    $gid = 'gid://shopify/Product/1';

    [$rate, $source] = callResolveCommissionRate($job, $gid, [$gid => 20.0], null, 15.0);

    expect($rate)->toBe(20.0);
    expect($source)->toBe('metafield_override');
});

it('returns rate_source=pending when metafield exceeds 100', function () {
    $job = makeResolveRateJob();
    $gid = 'gid://shopify/Product/1';
    $settings = (new BrandStoreSettings)->forceFill(['default_commission_rate' => 12.0]);

    [$rate, $source] = callResolveCommissionRate($job, $gid, [$gid => 150.0], $settings, 15.0);

    expect($source)->toBe('pending');
    // Fallback rate is brand default so commission maths remain valid.
    expect($rate)->toBe(12.0);
});

it('returns rate_source=pending when metafield is zero or negative', function () {
    $job = makeResolveRateJob();
    $gid = 'gid://shopify/Product/1';

    [$rate, $source] = callResolveCommissionRate($job, $gid, [$gid => 0.0], null, 15.0);

    expect($source)->toBe('pending');
    // Falls back to platform default when no brand settings.
    expect($rate)->toBe(15.0);
});

it('falls through to brand_default when no metafield is present', function () {
    $job = makeResolveRateJob();
    $gid = 'gid://shopify/Product/1';
    $settings = (new BrandStoreSettings)->forceFill(['default_commission_rate' => 10.0]);

    [$rate, $source] = callResolveCommissionRate($job, $gid, [], $settings, 15.0);

    expect($rate)->toBe(10.0);
    expect($source)->toBe('brand_default');
});

it('falls through to platform_default when metafield absent and no brand settings', function () {
    $job = makeResolveRateJob();

    [$rate, $source] = callResolveCommissionRate($job, 'gid://shopify/Product/1', [], null, 15.0);

    expect($rate)->toBe(15.0);
    expect($source)->toBe('platform_default');
});
