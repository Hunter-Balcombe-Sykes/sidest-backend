<?php

use App\Http\Requests\Api\BootstrapRequest;
use App\Http\Requests\Api\Professional\Site\DestroyLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\Professional\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;
use App\Http\Requests\Api\PublicSite\CustomerLeads\PublicCustomerLeadRequest;
use App\Http\Requests\Api\PublicSite\PublicSiteShowRequest;
use App\Http\Requests\Api\PublicSite\PublicWaitlistSignupRequest;
use Illuminate\Support\Facades\Validator;

it('rejects missing bootstrap fields', function () {
    $validator = Validator::make([], (new BootstrapRequest())->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('display_name'))->toBeTrue();
    expect($validator->errors()->has('primary_email'))->toBeTrue();
    expect($validator->errors()->has('phone'))->toBeTrue();
    expect($validator->errors()->has('first_name'))->toBeTrue();
    expect($validator->errors()->has('professional_type'))->toBeTrue();
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

it('rejects invalid public waitlist payload', function () {
    $payload = [
        'name' => '',
        'email' => 'bad-email',
        'phone' => 'abc',
        'type' => 'unknown',
        'industry' => 'unknown',
        'pilot_program_opt_in' => 'not-a-bool',
    ];

    $validator = Validator::make($payload, (new PublicWaitlistSignupRequest())->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('name'))->toBeTrue();
    expect($validator->errors()->has('email'))->toBeTrue();
    expect($validator->errors()->has('phone'))->toBeTrue();
    expect($validator->errors()->has('type'))->toBeTrue();
    expect($validator->errors()->has('industry'))->toBeTrue();
    expect($validator->errors()->has('pilot_program_opt_in'))->toBeTrue();
});

it('requires conditional fields for public waitlist payload', function () {
    $brandPayload = [
        'name' => 'Brand Owner',
        'email' => 'brand@example.com',
        'phone' => '+61411111111',
        'type' => 'brand',
        'industry' => 'beauty_products',
        'pilot_program_opt_in' => true,
    ];

    $brandValidator = Validator::make($brandPayload, (new PublicWaitlistSignupRequest())->rules());

    expect($brandValidator->fails())->toBeTrue();
    expect($brandValidator->errors()->has('number_of_team_members'))->toBeTrue();
    expect($brandValidator->errors()->has('number_of_affiliates_ambassadors'))->toBeTrue();

    $otherPayload = [
        'name' => 'Other Person',
        'email' => 'other@example.com',
        'phone' => '+61422222222',
        'type' => 'other',
        'industry' => 'other',
        'pilot_program_opt_in' => false,
    ];

    $otherValidator = Validator::make($otherPayload, (new PublicWaitlistSignupRequest())->rules());

    expect($otherValidator->fails())->toBeTrue();
    expect($otherValidator->errors()->has('type_other_text'))->toBeTrue();
    expect($otherValidator->errors()->has('industry_other_text'))->toBeTrue();
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
