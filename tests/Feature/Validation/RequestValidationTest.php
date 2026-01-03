<?php

use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Requests\Api\Professional\Site\DestroyLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\Professional\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;
use App\Http\Requests\Api\Public\CustomerLeads\PublicCustomerLeadRequest;
use App\Http\Requests\Api\Public\PublicSiteShowRequest;
use Illuminate\Support\Facades\Validator;

it('rejects missing bootstrap fields', function () {
    $validator = Validator::make([], (new BootstrapRequest())->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('handle'))->toBeTrue();
    expect($validator->errors()->has('primary_email'))->toBeTrue();
});

it('rejects invalid public customer lead payload', function () {
    $payload = [
        'full_name' => '',
        'email' => 'bad-email',
        'phone' => str_repeat('1', 51),
    ];

    $validator = Validator::make($payload, (new PublicCustomerLeadRequest())->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('full_name'))->toBeTrue();
    expect($validator->errors()->has('email'))->toBeTrue();
    expect($validator->errors()->has('phone'))->toBeTrue();
});

it('rejects invalid public site subdomain', function () {
    $payload = [
        'subdomain' => 'bad!subdomain',
    ];

    $validator = Validator::make($payload, (new PublicSiteShowRequest())->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('subdomain'))->toBeTrue();
});

it('rejects invalid link block store payload', function () {
    $payload = [
        'title' => str_repeat('a', 81),
        'url' => 'not-a-url',
    ];

    $validator = Validator::make($payload, (new StoreLinkBlockRequest())->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('title'))->toBeTrue();
    expect($validator->errors()->has('url'))->toBeTrue();
});

it('rejects invalid link block update payload', function () {
    $payload = [
        'id' => 'not-a-uuid',
        'url' => 'not-a-url',
    ];

    $validator = Validator::make($payload, (new UpdateLinkBlockRequest())->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('id'))->toBeTrue();
    expect($validator->errors()->has('url'))->toBeTrue();
});

it('rejects invalid reorder blocks payload', function () {
    $payload = [
        'ids' => ['not-a-uuid'],
    ];

    $validator = Validator::make($payload, (new ReorderBlocksRequest())->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('ids.0'))->toBeTrue();
});

it('rejects invalid destroy link block payload', function () {
    $payload = [
        'id' => 'not-a-uuid',
    ];

    $validator = Validator::make($payload, (new DestroyLinkBlockRequest())->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('id'))->toBeTrue();
});
