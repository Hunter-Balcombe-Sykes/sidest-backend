<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Http\Requests\Stripe\SyncPaymentMethodSessionRequest;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeBalanceService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeTransactionFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

// Verifies that Stripe API exceptions thrown by the service layer are caught,
// reported to Nightwatch via report($e), and surfaced as 422s instead of 500s.
//
// Before this fix: catch (\RuntimeException) silently swallowed errors without
// calling report($e) — Nightwatch never saw Stripe outages.
// After: \Stripe\Exception\ApiErrorException is caught + reported.

beforeEach(function () {
    setupProfessionalsTable();
    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT',
        'stripe_payment_method_brand TEXT',
        'stripe_payment_method_last4 TEXT',
    ] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }
});

function stripeReport_makeBrand(): \App\Models\Core\Professional\Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'brand-'.substr($id, 0, 8),
        'handle_lc' => 'brand-'.substr($id, 0, 8),
        'display_name' => 'Test Brand',
        'professional_type' => 'brand',
        'status' => 'active',
        'primary_email' => 'brand@stripe-test.example',
        'stripe_connect_account_id' => 'acct_test_123',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return \App\Models\Core\Professional\Professional::findOrFail($id);
}

function stripeReport_makeController(StripeConnectService $service): StripeConnectController
{
    $payoutService = Mockery::mock(CommissionPayoutService::class);
    $fetcherStub = Mockery::mock(StripeTransactionFetcher::class);

    return new StripeConnectController(
        $service,
        $payoutService,
        $fetcherStub,
        Mockery::mock(StripeBalanceService::class),
        app(CacheLockService::class),
    );
}

function stripeReport_makeService(string $method, \Throwable $exception): StripeConnectService
{
    $service = Mockery::mock(StripeConnectService::class);
    $service->shouldReceive($method)->andThrow($exception);

    return $service;
}

it('reports a Stripe ApiConnectionException from syncPaymentMethodSession and returns 422', function () {
    Exceptions::fake();
    Gate::before(fn () => true);

    $brand = stripeReport_makeBrand();
    $exception = new \Stripe\Exception\ApiConnectionException('Could not connect to Stripe.');
    $service = stripeReport_makeService('syncBrandPaymentMethodFromCheckoutSession', $exception);

    $controller = stripeReport_makeController($service);

    $request = SyncPaymentMethodSessionRequest::create('/api/stripe/payment-method/sync-session', 'POST', [
        'session_id' => 'cs_test_abc123',
    ]);
    $request->attributes->set('professional', $brand);

    $response = $controller->syncPaymentMethodSession($request);

    expect($response->getStatusCode())->toBe(422);
    Exceptions::assertReported(\Stripe\Exception\ApiConnectionException::class);
});
