<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeBalanceService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeTransactionFetcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

// Phase 3 — GET /stripe/balance + GET /stripe/payouts/upcoming
// Exercises the affiliate-only Earnings endpoints end-to-end:
//   - balance shape (available + pending + instant_available + schedule)
//   - upcoming payouts filter (pending|in_transit only)
//   - brand requesting either endpoint gets 403 affiliate_only
//   - 60s cache wrap (Stripe API hit once across two identical requests)
//   - graceful zero when no Stripe account is connected

beforeEach(function () {
    setupProfessionalsTable();
});

function earnings_seedPro(string $id, string $type, ?string $accountId = null): Professional
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $id,
        'handle_lc' => $id,
        'display_name' => 'Earnings Pro',
        'professional_type' => $type,
        'status' => 'active',
        'stripe_connect_account_id' => $accountId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($id);
}

function earnings_makeController(StripeClient $stripeMock): StripeConnectController
{
    return new StripeConnectController(
        Mockery::mock(StripeConnectService::class),
        Mockery::mock(CommissionPayoutService::class),
        Mockery::mock(StripeTransactionFetcher::class),
        new StripeBalanceService($stripeMock),
        app(CacheLockService::class),
    );
}

function earnings_makeRequest(Professional $pro): Request
{
    $request = Request::create('/api/stripe/balance', 'GET');
    $request->attributes->set('professional', $pro);

    return $request;
}

it('balance returns shaped AUD amounts plus schedule for an affiliate', function () {
    $aff = earnings_seedPro('aff-bal-1', 'influencer', 'acct_aff_1');

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->balance = Mockery::mock();
    $stripe->accounts = Mockery::mock();

    $stripe->balance->shouldReceive('retrieve')
        ->once()
        ->with([], ['stripe_account' => 'acct_aff_1'])
        ->andReturn((object) [
            'available' => [(object) ['amount' => 5000, 'currency' => 'aud']],
            'pending' => [(object) ['amount' => 1500, 'currency' => 'aud']],
            'instant_available' => [],
        ]);

    $stripe->accounts->shouldReceive('retrieve')
        ->once()
        ->with('acct_aff_1')
        ->andReturn((object) [
            'settings' => (object) [
                'payouts' => (object) [
                    'schedule' => (object) [
                        'interval' => 'daily',
                        'delay_days' => 2,
                    ],
                ],
            ],
        ]);

    $response = earnings_makeController($stripe)->balance(earnings_makeRequest($aff));

    expect($response->status())->toBe(200);
    $data = $response->getData(true);
    expect($data['balance']['available_cents'])->toBe(5000);
    expect($data['balance']['pending_cents'])->toBe(1500);
    expect($data['balance']['currency_code'])->toBe('AUD');
    expect($data['schedule']['interval'])->toBe('daily');
    expect($data['schedule']['delay_days'])->toBe(2);
});

it('balance ignores non-AUD currency buckets', function () {
    $aff = earnings_seedPro('aff-bal-2', 'influencer', 'acct_aff_2');

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->balance = Mockery::mock();
    $stripe->accounts = Mockery::mock()->shouldIgnoreMissing();

    $stripe->balance->shouldReceive('retrieve')->once()->andReturn((object) [
        'available' => [
            (object) ['amount' => 5000, 'currency' => 'aud'],
            (object) ['amount' => 9999, 'currency' => 'usd'],
        ],
        'pending' => [],
        'instant_available' => [],
    ]);

    $response = earnings_makeController($stripe)->balance(earnings_makeRequest($aff));
    $data = $response->getData(true);
    expect($data['balance']['available_cents'])->toBe(5000);
});

it('balance returns 403 for a brand', function () {
    $brand = earnings_seedPro('brand-bal', 'brand', 'acct_brand');

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->balance = Mockery::mock();
    $stripe->balance->shouldNotReceive('retrieve');

    $response = earnings_makeController($stripe)->balance(earnings_makeRequest($brand));
    expect($response->status())->toBe(403);
});

it('balance returns zeros gracefully when affiliate has no Stripe account', function () {
    $aff = earnings_seedPro('aff-noacct', 'influencer', null);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->balance = Mockery::mock();
    $stripe->balance->shouldNotReceive('retrieve');
    $stripe->accounts = Mockery::mock();
    $stripe->accounts->shouldNotReceive('retrieve');

    $response = earnings_makeController($stripe)->balance(earnings_makeRequest($aff));
    $data = $response->getData(true);
    expect($data['balance']['available_cents'])->toBe(0);
    expect($data['balance']['pending_cents'])->toBe(0);
    expect($data['schedule'])->toBeNull();
});

it('balance is cached — second request within 60s does not re-hit Stripe', function () {
    $aff = earnings_seedPro('aff-cache', 'influencer', 'acct_cache');

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->balance = Mockery::mock();
    $stripe->accounts = Mockery::mock()->shouldIgnoreMissing();

    $stripe->balance->shouldReceive('retrieve')->once()->andReturn((object) [
        'available' => [(object) ['amount' => 1234, 'currency' => 'aud']],
        'pending' => [],
        'instant_available' => [],
    ]);

    $controller = earnings_makeController($stripe);
    $first = $controller->balance(earnings_makeRequest($aff));
    $second = $controller->balance(earnings_makeRequest($aff));

    expect($first->status())->toBe(200);
    expect($second->status())->toBe(200);
    expect($first->getData(true))->toEqual($second->getData(true));
});

it('upcomingPayouts filters to pending and in_transit statuses only', function () {
    $aff = earnings_seedPro('aff-up-1', 'influencer', 'acct_up_1');

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->payouts = Mockery::mock();

    $stripe->payouts->shouldReceive('all')
        ->once()
        ->andReturn((object) [
            'data' => [
                (object) ['id' => 'po_pending', 'amount' => 500, 'currency' => 'aud', 'status' => 'pending', 'arrival_date' => 1746100000, 'method' => 'standard'],
                (object) ['id' => 'po_transit', 'amount' => 700, 'currency' => 'aud', 'status' => 'in_transit', 'arrival_date' => 1746200000, 'method' => 'standard'],
                (object) ['id' => 'po_paid', 'amount' => 900, 'currency' => 'aud', 'status' => 'paid', 'arrival_date' => 1745000000, 'method' => 'standard'],
                (object) ['id' => 'po_failed', 'amount' => 100, 'currency' => 'aud', 'status' => 'failed', 'arrival_date' => 1745500000, 'method' => 'standard'],
            ],
        ]);

    $request = Request::create('/api/stripe/payouts/upcoming', 'GET');
    $request->attributes->set('professional', $aff);

    $response = earnings_makeController($stripe)->upcomingPayouts($request);
    $data = $response->getData(true);

    expect($data['payouts'])->toHaveCount(2);
    expect(collect($data['payouts'])->pluck('id')->all())->toBe(['po_pending', 'po_transit']);
});

it('upcomingPayouts returns 403 for a brand', function () {
    $brand = earnings_seedPro('brand-up', 'brand', 'acct_brand_up');

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->payouts = Mockery::mock();
    $stripe->payouts->shouldNotReceive('all');

    $request = Request::create('/api/stripe/payouts/upcoming', 'GET');
    $request->attributes->set('professional', $brand);

    $response = earnings_makeController($stripe)->upcomingPayouts($request);
    expect($response->status())->toBe(403);
});
