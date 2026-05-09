<?php

use App\Http\Controllers\Api\Professional\Brand\BrandPayoutsController;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// GET /brand/payouts
// Authorization: manageWallet → brand-type only.

beforeEach(function () {
    tenantHelpersEnsureTables();

    $this->mock(StripeConnectService::class);
    $this->mock(CommissionPayoutService::class);

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL,
        stripe_payment_intent_id TEXT,
        stripe_transfer_id TEXT,
        status TEXT,
        gross_commission_cents INTEGER,
        platform_fee_cents INTEGER,
        net_payout_cents INTEGER,
        wallet_debit_cents INTEGER,
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
        funding_source TEXT,
        void_at TEXT,
        needs_manual_refund INTEGER DEFAULT 0,
        retry_count INTEGER DEFAULT 0,
        grace_notifications_sent TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');
});

function makeBrandPayoutsRequest(Professional $pro): Request
{
    $request = Request::create('/api/brand/payouts', 'GET');
    $request->attributes->set('professional', $pro);

    return $request;
}

function insertBrandPayout(array $attrs): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'status'                  => 'completed',
        'gross_commission_cents'  => 1000,
        'platform_fee_cents'      => 100,
        'net_payout_cents'        => 900,
        'currency_code'           => 'AUD',
        'funding_failure_count'   => 0,
        'created_at'              => $now,
        'updated_at'              => $now,
    ], $attrs));
}

it('returns a paginated list of brand payouts with affiliate name and lifecycle fields', function () {
    $brand = createBrandTenant('bp-brand-list');
    $aff   = createAffiliateTenant('bp-aff-list');

    insertBrandPayout([
        'id'                       => 'pay-brand-1',
        'brand_professional_id'    => $brand->id,
        'affiliate_professional_id'=> $aff->id,
        'net_payout_cents'         => 900,
        'failure_category'         => 'stripe_connect',
    ]);

    $controller = app(BrandPayoutsController::class);
    $resource   = $controller->index(makeBrandPayoutsRequest($brand));
    $response   = $resource->toResponse(makeBrandPayoutsRequest($brand));
    $body       = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($body['data'])->toHaveCount(1);

    $item = $body['data'][0];
    expect($item['id'])->toBe('pay-brand-1');
    // Lifecycle fields must be present on brand view.
    expect($item)->toHaveKeys([
        'failure_code', 'failure_category', 'failure_reason',
        'stripe_error_code', 'stripe_error_message',
        'funding_failure_count',
    ]);
    expect($item['failure_category'])->toBe('stripe_connect');
    // Affiliate relation
    expect($item['affiliate']['id'])->toBe($aff->id);
});

it('does not include payouts belonging to another brand', function () {
    $brandA = createBrandTenant('bp-brand-a');
    $brandB = createBrandTenant('bp-brand-b');
    $aff    = createAffiliateTenant('bp-aff-shared');

    insertBrandPayout([
        'id'                       => 'pay-a-only',
        'brand_professional_id'    => $brandA->id,
        'affiliate_professional_id'=> $aff->id,
    ]);
    insertBrandPayout([
        'id'                       => 'pay-b-only',
        'brand_professional_id'    => $brandB->id,
        'affiliate_professional_id'=> $aff->id,
    ]);

    $controller = app(BrandPayoutsController::class);
    $resource   = $controller->index(makeBrandPayoutsRequest($brandA));
    $response   = $resource->toResponse(makeBrandPayoutsRequest($brandA));
    $body       = json_decode($response->getContent(), true);

    $ids = collect($body['data'])->pluck('id')->all();
    expect($ids)->toContain('pay-a-only');
    expect($ids)->not->toContain('pay-b-only');
});

it('throws AuthorizationException when non-brand professional calls the controller', function () {
    $aff = createAffiliateTenant('bp-aff-direct-blocked');

    $controller = app(BrandPayoutsController::class);
    $controller->index(makeBrandPayoutsRequest($aff));
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);

// HTTP-layer guard — verifies route is secured at the HTTP level too.
it('returns 403 when affiliate calls the HTTP endpoint', function () {
    $aff = createAffiliateTenant('bp-aff-http');

    actingAsProfessional($aff)
        ->getJson('/api/brand/payouts')
        ->assertForbidden();
});
