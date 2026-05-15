<?php

use App\Http\Requests\Api\Internal\Embedded\UpdateProductSettingsRequest;
use Illuminate\Support\Facades\Validator;

// product_gid format ─────────────────────────────────────────────────────────

it('rejects when product_gid is missing', function () {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'field' => 'active',
        'value' => true,
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('product_gid'))->toBeTrue();
});

it('rejects product_gid with the wrong shape', function (string $bad) {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => $bad,
        'field' => 'active',
        'value' => true,
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('product_gid'))->toBeTrue();
})->with([
    'no_scheme' => 'shopify/Product/123',
    'wrong_resource' => 'gid://shopify/ProductVariant/123',
    'no_id' => 'gid://shopify/Product/',
    'random_string' => 'not a gid at all',
]);

it('accepts a well-formed product_gid', function () {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/9876543210',
        'field' => 'active',
        'value' => true,
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeFalse();
});

// field allowlist ────────────────────────────────────────────────────────────

it('rejects an unknown field outside the allowlist', function () {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'malicious_field',
        'value' => 'anything',
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('field'))->toBeTrue();
});

it('accepts every allowlisted field key', function (string $field) {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => $field,
        'value' => match ($field) {
            'commission_override', 'affiliate_discount_pct' => 12.5,
            'disabled_variant_gids' => ['gid://shopify/ProductVariant/1'],
            default => true,
        },
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeFalse();
})->with([
    'active',
    'commission_override',
    'affiliate_discount_pct',
    'custom_photos_enabled',
    'add_to_favourites',
    'add_to_default',
    'disabled_variant_gids',
]);

// numeric value rules — commission_override / affiliate_discount_pct ─────────

it('rejects non-numeric commission_override', function () {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'commission_override',
        'value' => 'not-a-number',
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('value'))->toBeTrue();
});

it('rejects commission_override outside 0..100', function (mixed $value) {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'commission_override',
        'value' => $value,
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('value'))->toBeTrue();
})->with([
    'below_min' => -1,
    'above_max' => 101,
    'far_above' => 9999,
]);

it('accepts commission_override at boundaries and decimals', function (mixed $value) {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'commission_override',
        'value' => $value,
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeFalse();
})->with([
    'low_boundary' => 0,
    'mid_decimal' => 12.5,
    'high_boundary' => 100,
]);

it('accepts a null commission_override (clears the override)', function () {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'commission_override',
        'value' => null,
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeFalse();
});

// boolean value rules ────────────────────────────────────────────────────────

it('rejects active when value is missing', function () {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'active',
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('value'))->toBeTrue();
});

it('accepts active with each bool-coercible value', function (mixed $value) {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'active',
        'value' => $value,
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeFalse();
})->with([
    'true_bool' => true,
    'false_bool' => false,
    'one' => 1,
    'zero' => 0,
    'string_one' => '1',
    'string_zero' => '0',
]);

// array value rules — disabled_variant_gids ─────────────────────────────────

it('rejects disabled_variant_gids entries with the wrong shape', function () {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'disabled_variant_gids',
        'value' => ['not-a-gid', 'gid://shopify/Product/123'],
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('value.0'))->toBeTrue();
    expect($v->errors()->has('value.1'))->toBeTrue();
});

it('accepts a list of well-formed variant GIDs', function () {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'disabled_variant_gids',
        'value' => [
            'gid://shopify/ProductVariant/1',
            'gid://shopify/ProductVariant/2',
        ],
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeFalse();
});

it('accepts an empty disabled_variant_gids array (re-enable all)', function () {
    $req = UpdateProductSettingsRequest::create('/dummy', 'PATCH', [
        'product_gid' => 'gid://shopify/Product/123',
        'field' => 'disabled_variant_gids',
        'value' => [],
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeFalse();
});
