<?php

use App\Http\Requests\Api\Internal\Embedded\ProvisionDomainTxtRequest;
use App\Http\Requests\Api\Internal\Embedded\ProvisionShopifyIntegrationRequest;
use App\Http\Requests\Api\Internal\Embedded\SaveBusinessDetailsRequest;
use App\Http\Requests\Api\Internal\Embedded\SaveDeploymentTokenRequest;
use App\Http\Requests\Api\Internal\Embedded\SaveIdentityRequest;
use App\Http\Requests\Api\Internal\Embedded\SetupDomainRequest;
use App\Http\Requests\Api\Internal\Embedded\UpdateSettingRequest;
use Illuminate\Support\Facades\Validator;

// SaveIdentityRequest ─────────────────────────────────────────────────────────

it('accepts an empty save-identity payload', function () {
    // All fields are 'sometimes' — the wizard saves partial updates.
    $v = Validator::make([], (new SaveIdentityRequest)->rules());
    expect($v->fails())->toBeFalse();
});

it('rejects save-identity with a bad email or non-url website', function () {
    $v = Validator::make([
        'contact_email' => 'not-an-email',
        'website_url' => 'not a url',
    ], (new SaveIdentityRequest)->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('contact_email'))->toBeTrue();
    expect($v->errors()->has('website_url'))->toBeTrue();
});

// SaveBusinessDetailsRequest ──────────────────────────────────────────────────

it('rejects save-business-details with missing required fields', function () {
    $v = Validator::make([], (new SaveBusinessDetailsRequest)->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('legal_business_name'))->toBeTrue();
    expect($v->errors()->has('abn'))->toBeTrue();
    expect($v->errors()->has('business_type'))->toBeTrue();
    expect($v->errors()->has('industries'))->toBeTrue();
});

it('rejects save-business-details when industries is not an array', function () {
    $v = Validator::make([
        'legal_business_name' => 'Acme Pty Ltd',
        'abn' => '12345678901',
        'business_type' => 'company',
        'industries' => 'beauty',
    ], (new SaveBusinessDetailsRequest)->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('industries'))->toBeTrue();
});

it('accepts a valid save-business-details payload', function () {
    $v = Validator::make([
        'legal_business_name' => 'Acme Pty Ltd',
        'abn' => '12345678901',
        'business_type' => 'company',
        'industries' => ['beauty_products', 'wellness'],
    ], (new SaveBusinessDetailsRequest)->rules());

    expect($v->fails())->toBeFalse();
});

// UpdateSettingRequest ────────────────────────────────────────────────────────

it('rejects update-setting with an unknown key', function () {
    $v = Validator::make([
        'key' => 'arbitrary_key',
        'value' => '0.10',
    ], (new UpdateSettingRequest)->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('key'))->toBeTrue();
});

it('accepts update-setting with each allowed key', function () {
    foreach (['default_commission_rate', 'theme_id', 'setup_complete'] as $key) {
        $v = Validator::make([
            'key' => $key,
            'value' => '1',
        ], (new UpdateSettingRequest)->rules());

        expect($v->fails())->toBeFalse();
    }
});

// The next tests construct the request with bound input via Request::create so
// rules() can read $this->input('key') and apply the key-aware value rules.
// (new UpdateSettingRequest)->rules() returns the default-branch rules only.

it('rejects default_commission_rate when value is not numeric', function () {
    $req = UpdateSettingRequest::create('/dummy', 'POST', [
        'key' => 'default_commission_rate',
        'value' => 'not-a-number',
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('value'))->toBeTrue();
});

it('rejects default_commission_rate when value is outside 0..100', function (string $value) {
    $req = UpdateSettingRequest::create('/dummy', 'POST', [
        'key' => 'default_commission_rate',
        'value' => $value,
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('value'))->toBeTrue();
})->with([
    'below_min' => '-1',
    'above_max' => '101',
    'far_above' => '9999',
]);

it('accepts default_commission_rate when value is within 0..100 inclusive', function (string $value) {
    $req = UpdateSettingRequest::create('/dummy', 'POST', [
        'key' => 'default_commission_rate',
        'value' => $value,
    ]);

    $v = Validator::make($req->all(), $req->rules());

    expect($v->fails())->toBeFalse();
})->with([
    'boundary_low' => '0',
    'mid_decimal' => '12.5',
    'boundary_high' => '100',
]);

// SaveDeploymentTokenRequest ──────────────────────────────────────────────────

it('rejects save-deployment-token with no token', function () {
    $v = Validator::make([], (new SaveDeploymentTokenRequest)->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('token'))->toBeTrue();
});

it('accepts save-deployment-token with token only or token + storefront_id', function () {
    $tokenOnly = Validator::make(['token' => 'tok_abc'], (new SaveDeploymentTokenRequest)->rules());
    expect($tokenOnly->fails())->toBeFalse();

    $withStorefront = Validator::make([
        'token' => 'tok_abc',
        'storefront_id' => 'gid://shopify/HydrogenStorefront/123',
    ], (new SaveDeploymentTokenRequest)->rules());
    expect($withStorefront->fails())->toBeFalse();
});

// SetupDomainRequest ──────────────────────────────────────────────────────────

it('rejects setup-domain with a bad subdomain format', function () {
    $v = Validator::make([
        'oxygen_storefront_id' => 'gid://shopify/HydrogenStorefront/123',
        'subdomain' => 'NotALabel!',
    ], (new SetupDomainRequest)->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('subdomain'))->toBeTrue();
});

it('accepts setup-domain with a valid label', function () {
    $v = Validator::make([
        'oxygen_storefront_id' => 'gid://shopify/HydrogenStorefront/123',
        'subdomain' => 'acme-brand',
    ], (new SetupDomainRequest)->rules());

    expect($v->fails())->toBeFalse();
});

// ProvisionDomainTxtRequest ───────────────────────────────────────────────────

it('rejects provision-domain-txt with no txt_value', function () {
    $v = Validator::make([], (new ProvisionDomainTxtRequest)->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('txt_value'))->toBeTrue();
});

it('rejects provision-domain-txt with txt_value over 255 chars', function () {
    $v = Validator::make([
        'txt_value' => str_repeat('a', 256),
    ], (new ProvisionDomainTxtRequest)->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('txt_value'))->toBeTrue();
});

// ProvisionShopifyIntegrationRequest ──────────────────────────────────────────

it('rejects provision-shopify-integration with no access_token', function () {
    $v = Validator::make([], (new ProvisionShopifyIntegrationRequest)->rules());

    expect($v->fails())->toBeTrue();
    expect($v->errors()->has('access_token'))->toBeTrue();
});

it('accepts provision-shopify-integration with access_token only', function () {
    $v = Validator::make([
        'access_token' => 'shpat_abc123',
    ], (new ProvisionShopifyIntegrationRequest)->rules());

    expect($v->fails())->toBeFalse();
});

it('accepts provision-shopify-integration with all optional fields', function () {
    $v = Validator::make([
        'access_token' => 'shpat_abc123',
        'shop_id' => 'gid://shopify/Shop/123',
        'scopes' => 'read_products,write_metafields,read_orders',
    ], (new ProvisionShopifyIntegrationRequest)->rules());

    expect($v->fails())->toBeFalse();
});
