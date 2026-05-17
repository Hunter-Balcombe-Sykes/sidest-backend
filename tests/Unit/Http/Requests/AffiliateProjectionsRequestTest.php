<?php

uses(Tests\TestCase::class);

use App\Http\Requests\Api\Professional\Analytics\AffiliateProjectionsRequest;
use Illuminate\Support\Facades\Validator;

it('accepts no params (defaults to adaptive window)', function () {
    $rules = (new AffiliateProjectionsRequest)->rules();
    $v = Validator::make([], $rules);
    expect($v->passes())->toBeTrue();
});

it('accepts window_days from the allowlist', function () {
    $rules = (new AffiliateProjectionsRequest)->rules();
    foreach ([14, 30, 60, 90] as $days) {
        $v = Validator::make(['window_days' => $days], $rules);
        expect($v->passes())->toBeTrue();
    }
});

it('rejects window_days outside the allowlist', function () {
    $rules = (new AffiliateProjectionsRequest)->rules();
    foreach ([0, 7, 365, -1, 'abc'] as $bad) {
        $v = Validator::make(['window_days' => $bad], $rules);
        expect($v->passes())->toBeFalse();
    }
});
