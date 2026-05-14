<?php

use App\Http\Controllers\Api\Professional\Brand\BrandBillingSummaryController;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// GET /brand/billing-summary
// Authorization: manageWallet → brand-type only.

beforeEach(function () {
    tenantHelpersEnsureTables();

    // These Stripe services require a live API key at construction time.
    // Bind stubs so the controller container can resolve without a real key.
    $this->mock(StripeConnectService::class);
    $this->mock(CommissionPayoutService::class);

    // Controller queries commerce.orders for blocked-order count.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.orders (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        status TEXT NULL,
        payout_id TEXT NULL,
        refund_cents INTEGER NULL DEFAULT 0,
        commission_cents INTEGER NULL DEFAULT 0,
        rate_source TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

/**
 * Builds a Request with the professional injected as the `professional` attribute,
 * mirroring what LoadCurrentProfessional middleware does at runtime.
 */
function makeBillingSummaryRequest(Professional $pro): Request
{
    $request = Request::create('/api/brand/billing-summary', 'GET');
    $request->attributes->set('professional', $pro);

    return $request;
}

it('returns billing summary with masked card for a brand with a card on their Connect account', function () {
    $brand = createBrandTenant('billing-brand-card');

    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_payment_method_id TEXT NULL',
        'stripe_payment_method_brand TEXT NULL',
        'stripe_payment_method_last4 TEXT NULL',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE \"core\".\"professionals\" ADD COLUMN {$col}");
        } catch (\Throwable) {
            // Column already exists — ignore.
        }
    }

    $conn->table('core.professionals')
        ->where('id', $brand->id)
        ->update([
            'stripe_payment_method_id' => 'pm_on_brand_4242',
            'stripe_payment_method_brand' => 'visa',
            'stripe_payment_method_last4' => '4242',
        ]);
    $brand->refresh();

    $controller = app(BrandBillingSummaryController::class);
    $response = $controller->show(makeBillingSummaryRequest($brand));
    $body = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($body)->toHaveKeys([
        'has_card', 'masked_card', 'blocked_orders_count', 'blocked_pending_cents', 'currency',
    ]);
    expect($body['has_card'])->toBeTrue();
    expect($body['masked_card']['brand'])->toBe('visa');
    expect($body['masked_card']['last4'])->toBe('4242');
    expect($body['blocked_orders_count'])->toBe(0);
    expect($body['blocked_pending_cents'])->toBe(0);
    expect($body['currency'])->toBe('AUD');
});

it('returns null masked_card when brand has no payment method', function () {
    $brand = createBrandTenant('billing-brand-nocard');

    $controller = app(BrandBillingSummaryController::class);
    $response = $controller->show(makeBillingSummaryRequest($brand));
    $body = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($body['has_card'])->toBeFalse();
    expect($body['masked_card'])->toBeNull();
    expect($body['blocked_orders_count'])->toBe(0);
});

it('throws AuthorizationException when non-brand professional calls the controller', function () {
    $aff = createAffiliateTenant('billing-aff-direct');

    $controller = app(BrandBillingSummaryController::class);
    $controller->show(makeBillingSummaryRequest($aff));
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);

// HTTP-layer guard — brand.only middleware is not applied here, but the Gate in
// the controller must still deny non-brands at the route level for affiliate accounts.
it('returns 403 when affiliate calls the HTTP endpoint', function () {
    $aff = createAffiliateTenant('billing-aff-http');

    actingAsProfessional($aff)
        ->getJson('/api/brand/billing-summary')
        ->assertForbidden();
});

it('returns 401 for unauthenticated requests', function () {
    $this->getJson('/api/brand/billing-summary')
        ->assertUnauthorized();
});
