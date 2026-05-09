<?php

use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;

beforeEach(function () {
    tenantHelpersEnsureTables();

    // Stripe services require a live API key at construction time. Bind stubs so
    // HTTP-layer tests can resolve the controller without a real Stripe connection.
    $this->mock(StripeConnectService::class);
    $this->mock(CommissionPayoutService::class);
});

it('rejects affiliate calling brand-only top-up route with 403', function () {
    $aff = createAffiliateTenant('aff-test');
    actingAsProfessional($aff);
    $this->postJson('/api/stripe/topups/checkout', [
        'amount_cents' => 5000,
        'success_url'  => 'https://example.test/ok',
        'cancel_url'   => 'https://example.test/no',
    ])->assertForbidden();
});

it('rejects unauthenticated request with 401', function () {
    $this->postJson('/api/stripe/topups/checkout', [
        'amount_cents' => 5000,
        'success_url'  => 'https://example.test/ok',
        'cancel_url'   => 'https://example.test/no',
    ])->assertUnauthorized();
});

it('returns 422 for missing amount_cents', function () {
    $brand = createBrandTenant('brand-test');
    actingAsProfessional($brand);
    $this->postJson('/api/stripe/topups/checkout', [
        'success_url' => 'https://example.test/ok',
        'cancel_url'  => 'https://example.test/no',
    ])->assertUnprocessable();
});
