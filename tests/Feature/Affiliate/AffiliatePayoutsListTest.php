<?php

use App\Http\Controllers\Api\Professional\Affiliate\AffiliatePayoutsController;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// GET /affiliate/payouts
// Authorization: CommissionPolicy::view — affiliates see own rows; brands are denied.

beforeEach(function () {
    tenantHelpersEnsureTables();

    $this->mock(StripeConnectService::class);
    $this->mock(CommissionPayoutService::class);

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL,
        payment_intent_id TEXT,
        charge_id TEXT,
        status TEXT,
        gross_commission_cents INTEGER,
        platform_fee_cents INTEGER,
        net_payout_cents INTEGER,
        charge_cents INTEGER,
        currency_code TEXT,
        failure_code TEXT,
        failure_category TEXT,
        failure_reason TEXT,
        stripe_error_code TEXT,
        stripe_error_message TEXT,
        funding_failure_count INTEGER DEFAULT 0,
        next_retry_at TEXT,
        last_retry_at TEXT,
        transfer_completed_at TEXT,
        ledger_entry_count INTEGER,
        eligible_after TEXT,
        processed_at TEXT,
        void_at TEXT,
        needs_manual_refund INTEGER DEFAULT 0,
        retry_count INTEGER DEFAULT 0,
        grace_notifications_sent TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');
});

function makeAffiliatePayoutsRequest(Professional $pro): Request
{
    $request = Request::create('/api/affiliate/payouts', 'GET');
    $request->attributes->set('professional', $pro);

    return $request;
}

function insertAffiliatePayout(array $attrs): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'status' => 'completed',
        'gross_commission_cents' => 2000,
        'platform_fee_cents' => 200,
        'net_payout_cents' => 1800,
        'currency_code' => 'AUD',
        'funding_failure_count' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $attrs));
}

it('returns a paginated list of payouts for the authenticated affiliate', function () {
    $brand = createBrandTenant('ap-brand-list');
    $aff = createAffiliateTenant('ap-aff-list');

    insertAffiliatePayout([
        'id' => 'pay-aff-1',
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $aff->id,
    ]);

    $controller = app(AffiliatePayoutsController::class);
    $resource = $controller->index(makeAffiliatePayoutsRequest($aff));
    $response = $resource->toResponse(makeAffiliatePayoutsRequest($aff));
    $body = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($body['data'])->toHaveCount(1);

    $item = $body['data'][0];
    expect($item['id'])->toBe('pay-aff-1');
    expect($item['brand']['id'])->toBe($brand->id);
});

it('does not include payouts belonging to another affiliate', function () {
    $brand = createBrandTenant('ap-brand-shared');
    $affA = createAffiliateTenant('ap-aff-a');
    $affB = createAffiliateTenant('ap-aff-b');

    insertAffiliatePayout([
        'id' => 'pay-for-a',
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $affA->id,
    ]);
    insertAffiliatePayout([
        'id' => 'pay-for-b',
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $affB->id,
    ]);

    $controller = app(AffiliatePayoutsController::class);
    $resource = $controller->index(makeAffiliatePayoutsRequest($affA));
    $response = $resource->toResponse(makeAffiliatePayoutsRequest($affA));
    $body = json_decode($response->getContent(), true);

    $ids = collect($body['data'])->pluck('id')->all();
    expect($ids)->toContain('pay-for-a');
    expect($ids)->not->toContain('pay-for-b');
});

it('redacts failure_category=brand_funding from the affiliate response', function () {
    $brand = createBrandTenant('ap-brand-redact');
    $aff = createAffiliateTenant('ap-aff-redact');

    insertAffiliatePayout([
        'id' => 'pay-funding-fail',
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $aff->id,
        'status' => 'failed',
        'failure_category' => 'brand_funding',
        'failure_reason' => 'Brand wallet empty',
    ]);

    $controller = app(AffiliatePayoutsController::class);
    $resource = $controller->index(makeAffiliatePayoutsRequest($aff));
    $response = $resource->toResponse(makeAffiliatePayoutsRequest($aff));
    $body = json_decode($response->getContent(), true);

    // brand_funding must be redacted — affiliate sees null.
    expect($body['data'][0]['failure_category'])->toBeNull();
    // failure_reason still visible so affiliate knows to contact support.
    expect($body['data'][0]['failure_reason'])->toBe('Brand wallet empty');
});

it('does not redact other failure categories from the affiliate response', function () {
    $brand = createBrandTenant('ap-brand-redact2');
    $aff = createAffiliateTenant('ap-aff-redact2');

    insertAffiliatePayout([
        'id' => 'pay-stripe-fail',
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $aff->id,
        'status' => 'failed',
        'failure_category' => 'stripe_connect',
    ]);

    $controller = app(AffiliatePayoutsController::class);
    $resource = $controller->index(makeAffiliatePayoutsRequest($aff));
    $response = $resource->toResponse(makeAffiliatePayoutsRequest($aff));
    $body = json_decode($response->getContent(), true);

    // stripe_connect failures are visible — the affiliate needs to fix their account.
    expect($body['data'][0]['failure_category'])->toBe('stripe_connect');
});

it('throws AuthorizationException when brand calls the affiliate payouts controller', function () {
    $brand = createBrandTenant('ap-brand-blocked-direct');

    $controller = app(AffiliatePayoutsController::class);
    $controller->index(makeAffiliatePayoutsRequest($brand));
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);

// HTTP-layer guard.
it('returns 403 when brand calls the affiliate payouts HTTP endpoint', function () {
    $brand = createBrandTenant('ap-brand-http');

    actingAsProfessional($brand)
        ->getJson('/api/affiliate/payouts')
        ->assertForbidden();
});
