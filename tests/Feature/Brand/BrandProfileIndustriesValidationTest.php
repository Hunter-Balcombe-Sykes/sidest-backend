<?php

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Direct Validator-level coverage for the industries rule applied in
 * BrandProfileController::update. No DB, no HTTP stack — the rule is the
 * test's subject, and the controller composes it with the other field rules
 * unchanged. If the controller's rule set evolves, update this helper to
 * match.
 */
function validateBrandIndustries(array $payload): array
{
    $rules = [
        'industries' => ['sometimes', 'array', 'max:3'],
        'industries.*' => [
            'string',
            Rule::in(array_keys(config('sidest.brand_industries', []))),
        ],
    ];

    $validator = Validator::make($payload, $rules);

    return $validator->fails()
        ? ['ok' => false, 'errors' => $validator->errors()->toArray()]
        : ['ok' => true, 'data' => $validator->validated()];
}

it('accepts a single valid industry slug', function () {
    $result = validateBrandIndustries(['industries' => ['haircare']]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['industries'])->toBe(['haircare']);
});

it('accepts multiple valid slugs and preserves order (first-is-primary)', function () {
    $result = validateBrandIndustries([
        'industries' => ['skin_care', 'haircare', 'fragrance'],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['industries'])->toBe(['skin_care', 'haircare', 'fragrance']);
});

it('rejects an unknown industry slug with a per-index error', function () {
    $result = validateBrandIndustries(['industries' => ['surfboards']]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('industries.0');
});

it('rejects a mix of valid and invalid slugs, flagging the bad index', function () {
    $result = validateBrandIndustries([
        'industries' => ['haircare', 'not_a_real_industry'],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('industries.1');
    expect($result['errors'])->not->toHaveKey('industries.0');
});

it('rejects more than 3 industries', function () {
    $result = validateBrandIndustries([
        'industries' => ['apparel', 'footwear', 'accessories', 'skin_care'],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('industries');
});

it('accepts an empty industries array (profile not yet set up)', function () {
    $result = validateBrandIndustries(['industries' => []]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a payload with no industries key at all (partial update)', function () {
    $result = validateBrandIndustries([]);

    expect($result['ok'])->toBeTrue();
});

it('accepts every slug defined in the config', function () {
    $allSlugs = array_keys(config('sidest.brand_industries'));

    // Config exposes 13 slugs; cap at 3 per the rule, so test chunks of 3.
    foreach (array_chunk($allSlugs, 3) as $chunk) {
        $result = validateBrandIndustries(['industries' => $chunk]);
        expect($result['ok'])->toBeTrue();
    }
});
