<?php

/** @phpstan-ignore-all */

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;

/**
 * Isolated coverage for the sidest_marketing_opt_in cart-attribute parser in
 * ProcessShopifyOrderWebhookJob. The parser is a private method; we poke at it
 * via reflection so we don't have to stand up the whole webhook pipeline.
 *
 * The accepted truthy/falsy values are part of the integration contract with
 * Hydrogen — if this test fails, someone changed the shape without updating
 * the storefront.
 */
beforeEach(function () {
    $job = new ProcessShopifyOrderWebhookJob('00000000-0000-0000-0000-000000000000', []);
    $method = new ReflectionMethod($job, 'parseMarketingOptInAttribute');
    $method->setAccessible(true);

    test()->parse = fn (mixed $attrs) => $method->invoke($job, $attrs);
});

it('returns null when the attribute is missing', function () {
    expect((test()->parse)([]))->toBeNull();
    expect((test()->parse)(null))->toBeNull();
    expect((test()->parse)([
        ['name' => 'other_key', 'value' => 'true'],
    ]))->toBeNull();
});

it('returns true for documented truthy values (case-insensitive)', function () {
    foreach (['true', 'TRUE', '1', 'yes', 'Yes', 'YES'] as $value) {
        $attrs = [['name' => 'sidest_marketing_opt_in', 'value' => $value]];
        expect((test()->parse)($attrs))->toBeTrue("Expected '{$value}' to parse as true");
    }
});

it('returns false for documented falsy values (case-insensitive)', function () {
    foreach (['false', 'FALSE', '0', 'no', 'No', 'NO'] as $value) {
        $attrs = [['name' => 'sidest_marketing_opt_in', 'value' => $value]];
        expect((test()->parse)($attrs))->toBeFalse("Expected '{$value}' to parse as false");
    }
});

it('returns null for unrecognized values so typos do not silently flip consent', function () {
    foreach (['maybe', 'idk', 'please', ''] as $value) {
        $attrs = [['name' => 'sidest_marketing_opt_in', 'value' => $value]];
        expect((test()->parse)($attrs))->toBeNull("Expected '{$value}' to parse as null");
    }
});
