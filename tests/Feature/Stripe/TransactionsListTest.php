<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Http\Requests\Api\Professional\Stripe\TransactionsRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeTransactionFetcher;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

// Phase 1 — GET /stripe/transactions
// Exercises StripeConnectController::transactions() end-to-end:
//   - policy gating via CommissionPolicy::viewOwnTransactions (cross-role 403)
//   - role-scoped fetcher dispatch (brand → forBrand, affiliate → forAffiliate)
//   - row shape from the fetcher's normalisers (charges, refunds, transfers, reversals)
//   - 60s CacheLockService wrap (second call within window doesn't re-hit Stripe)
//
// The StripeClient is mocked; the rest of the chain (fetcher, cache, controller, policy) runs real.

beforeEach(function () {
    setupProfessionalsTable();

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
        currency_code TEXT,
        ledger_entry_count INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');
});

function txns_seedPro(string $id, string $type, string $name = 'Test Pro'): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $id,
        'handle_lc' => $id,
        'display_name' => $name,
        'professional_type' => $type,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function txns_seedPayout(array $attrs): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'status' => 'completed',
        'gross_commission_cents' => 1000,
        'platform_fee_cents' => 100,
        'net_payout_cents' => 900,
        'currency_code' => 'AUD',
        'ledger_entry_count' => 2,
        'created_at' => $now,
        'updated_at' => $now,
    ], $attrs));
}

function txns_pi(string $id, int $amount, ?array $refunds = []): object
{
    $charge = (object) [
        'id' => 'ch_'.$id,
        'amount' => $amount,
        'currency' => 'aud',
        'status' => 'succeeded',
        'description' => null,
        'created' => 1746000000,
        'payment_intent' => $id,
        'refunds' => (object) ['data' => array_map(fn ($r) => (object) $r, $refunds ?? [])],
    ];

    return (object) [
        'id' => $id,
        'latest_charge' => $charge,
    ];
}

function txns_charge(string $id, int $transferAmount, ?array $reversals = []): object
{
    $transfer = (object) [
        'id' => 'tr_'.$id,
        'amount' => $transferAmount,
        'currency' => 'aud',
        'created' => 1746000000,
        'description' => 'Partna commission from BrandCo (payout x, 2 orders)',
        'destination_payment' => 'py_'.$id,
        'reversals' => (object) ['data' => array_map(fn ($r) => (object) $r, $reversals ?? [])],
    ];

    return (object) [
        'id' => $id,
        'transfer' => $transfer,
    ];
}

function txns_makeController(StripeClient $stripeMock): StripeConnectController
{
    return new StripeConnectController(
        Mockery::mock(StripeConnectService::class),
        Mockery::mock(CommissionPayoutService::class),
        new StripeTransactionFetcher($stripeMock),
        app(CacheLockService::class),
    );
}

function txns_makeRequest(Professional $pro, array $query = []): TransactionsRequest
{
    $query = array_merge(['role' => 'affiliate'], $query);
    $request = TransactionsRequest::create('/api/stripe/transactions', 'GET', $query);
    $request->attributes->set('professional', $pro);

    return $request;
}

it('brand role returns charge rows for own payouts, with refunds as negative siblings', function () {
    txns_seedPro('pro-brand-1', 'brand', 'BrandCo');
    txns_seedPro('pro-aff-1', 'influencer', 'Aff One');

    txns_seedPayout([
        'id' => 'payout-1',
        'brand_professional_id' => 'pro-brand-1',
        'affiliate_professional_id' => 'pro-aff-1',
        'payment_intent_id' => 'pi_001',
        'charge_id' => 'ch_001',
        'ledger_entry_count' => 3,
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->charges = Mockery::mock();

    $pi = txns_pi('pi_001', 1500, [
        ['id' => 're_001', 'amount' => 500, 'currency' => 'aud', 'status' => 'succeeded', 'created' => 1746100000],
    ]);
    $stripe->paymentIntents->shouldReceive('retrieve')
        ->once()
        ->with('pi_001', ['expand' => ['latest_charge.refunds']])
        ->andReturn($pi);

    $brand = Professional::find('pro-brand-1');
    $response = txns_makeController($stripe)->transactions(txns_makeRequest($brand, ['role' => 'brand']));

    expect($response->status())->toBe(200);
    $data = $response->getData(true);
    $rows = $data['transactions'];

    expect($rows)->toHaveCount(2);

    $charge = collect($rows)->firstWhere('type', 'charge');
    expect($charge['amount_cents'])->toBe(1500);
    expect($charge['payout_id'])->toBe('payout-1');
    expect($charge['orders_count'])->toBe(3);
    expect($charge['brand']['name'])->toBe('BrandCo');
    expect($charge['stripe_dashboard_url'])->toBe('https://dashboard.stripe.com/payments/pi_001');

    $refund = collect($rows)->firstWhere('type', 'refund');
    expect($refund['amount_cents'])->toBe(-500);  // negative
    expect($refund['payout_id'])->toBe('payout-1');
});

it('affiliate role returns transfer rows with destination_payment deep-link', function () {
    txns_seedPro('pro-brand-2', 'brand', 'BrandCo');
    txns_seedPro('pro-aff-2', 'influencer', 'Aff Two');

    // Affiliate-side deep-link includes their connect_account_id (column added by setupProfessionalsTable).
    DB::connection('pgsql')->table('core.professionals')
        ->where('id', 'pro-aff-2')
        ->update(['stripe_connect_account_id' => 'acct_aff_2']);

    txns_seedPayout([
        'id' => 'payout-2',
        'brand_professional_id' => 'pro-brand-2',
        'affiliate_professional_id' => 'pro-aff-2',
        'payment_intent_id' => 'pi_002',
        'charge_id' => 'ch_002',
        'ledger_entry_count' => 2,
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->charges = Mockery::mock();

    $charge = txns_charge('002', 900);
    $stripe->charges->shouldReceive('retrieve')
        ->once()
        ->with('ch_002', ['expand' => ['transfer.reversals']])
        ->andReturn($charge);

    $aff = Professional::find('pro-aff-2');
    $response = txns_makeController($stripe)->transactions(txns_makeRequest($aff, ['role' => 'affiliate']));

    expect($response->status())->toBe(200);
    $data = $response->getData(true);
    $rows = $data['transactions'];

    expect($rows)->toHaveCount(1);
    $transfer = $rows[0];
    expect($transfer['type'])->toBe('transfer');
    expect($transfer['amount_cents'])->toBe(900);
    expect($transfer['description'])->toContain('Partna commission from BrandCo');
    expect($transfer['raw_stripe_id'])->toBe('py_002');
    expect($transfer['stripe_dashboard_url'])->toBe('https://dashboard.stripe.com/connect/accounts/acct_aff_2/payments/py_002');
});

it('emits reversal rows as negative siblings of the transfer', function () {
    txns_seedPro('pro-brand-3', 'brand', 'BrandCo');
    txns_seedPro('pro-aff-3', 'influencer', 'Aff Three');

    txns_seedPayout([
        'id' => 'payout-3',
        'brand_professional_id' => 'pro-brand-3',
        'affiliate_professional_id' => 'pro-aff-3',
        'charge_id' => 'ch_003',
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->charges = Mockery::mock();

    $charge = txns_charge('003', 900, [
        ['id' => 'trr_003', 'amount' => 200, 'currency' => 'aud', 'created' => 1746200000],
    ]);
    $stripe->charges->shouldReceive('retrieve')->once()->andReturn($charge);

    $aff = Professional::find('pro-aff-3');
    $response = txns_makeController($stripe)->transactions(txns_makeRequest($aff, ['role' => 'affiliate']));

    $rows = $response->getData(true)['transactions'];
    expect($rows)->toHaveCount(2);

    $reversal = collect($rows)->firstWhere('type', 'reversal');
    expect($reversal['amount_cents'])->toBe(-200);
});

it('denies a brand calling /stripe/transactions with role=affiliate (cross-role)', function () {
    txns_seedPro('pro-cross-1', 'brand', 'CrossBrand');

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();
    $stripe->paymentIntents->shouldNotReceive('retrieve');

    $brand = Professional::find('pro-cross-1');

    expect(fn () => txns_makeController($stripe)->transactions(
        txns_makeRequest($brand, ['role' => 'affiliate'])
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('denies an affiliate calling /stripe/transactions with role=brand (cross-role)', function () {
    txns_seedPro('pro-cross-2', 'influencer', 'CrossAff');

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->charges = Mockery::mock();
    $stripe->charges->shouldNotReceive('retrieve');

    $aff = Professional::find('pro-cross-2');

    expect(fn () => txns_makeController($stripe)->transactions(
        txns_makeRequest($aff, ['role' => 'brand'])
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('filters rows by type when type=charge', function () {
    txns_seedPro('pro-brand-f', 'brand', 'FilterBrand');
    txns_seedPro('pro-aff-f', 'influencer', 'Aff F');

    txns_seedPayout([
        'id' => 'payout-f',
        'brand_professional_id' => 'pro-brand-f',
        'affiliate_professional_id' => 'pro-aff-f',
        'payment_intent_id' => 'pi_filter',
        'charge_id' => 'ch_filter',
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();

    $pi = txns_pi('pi_filter', 1000, [
        ['id' => 're_f', 'amount' => 200, 'currency' => 'aud', 'status' => 'succeeded', 'created' => 1746100000],
    ]);
    $stripe->paymentIntents->shouldReceive('retrieve')->once()->andReturn($pi);

    $brand = Professional::find('pro-brand-f');
    $response = txns_makeController($stripe)->transactions(txns_makeRequest($brand, [
        'role' => 'brand',
        'type' => 'charge',
    ]));

    $rows = $response->getData(true)['transactions'];
    expect($rows)->toHaveCount(1);
    expect($rows[0]['type'])->toBe('charge');
});

it('caches results — second identical request does not re-hit Stripe within 60s', function () {
    txns_seedPro('pro-brand-c', 'brand', 'CacheBrand');
    txns_seedPro('pro-aff-c', 'influencer', 'Aff C');

    txns_seedPayout([
        'id' => 'payout-c',
        'brand_professional_id' => 'pro-brand-c',
        'affiliate_professional_id' => 'pro-aff-c',
        'payment_intent_id' => 'pi_cache',
        'charge_id' => 'ch_cache',
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();

    $pi = txns_pi('pi_cache', 700);
    // EXACTLY ONE retrieve across two requests — the cache must serve the second.
    $stripe->paymentIntents->shouldReceive('retrieve')->once()->andReturn($pi);

    $controller = txns_makeController($stripe);
    $brand = Professional::find('pro-brand-c');
    $req = txns_makeRequest($brand, ['role' => 'brand']);

    $first = $controller->transactions($req);
    $second = $controller->transactions(txns_makeRequest($brand, ['role' => 'brand']));

    expect($first->status())->toBe(200);
    expect($second->status())->toBe(200);
    expect($first->getData(true))->toEqual($second->getData(true));
});

it('skips payouts whose Stripe retrieve throws — does not abort the whole list', function () {
    txns_seedPro('pro-brand-e', 'brand', 'ErrBrand');
    txns_seedPro('pro-aff-e', 'influencer', 'Aff E');

    txns_seedPayout([
        'id' => 'payout-good',
        'brand_professional_id' => 'pro-brand-e',
        'affiliate_professional_id' => 'pro-aff-e',
        'payment_intent_id' => 'pi_good',
        'charge_id' => 'ch_good',
        'created_at' => '2026-05-01 10:00:00',
    ]);
    txns_seedPayout([
        'id' => 'payout-broken',
        'brand_professional_id' => 'pro-brand-e',
        'affiliate_professional_id' => 'pro-aff-e',
        'payment_intent_id' => 'pi_broken',
        'charge_id' => 'ch_broken',
        'created_at' => '2026-05-02 10:00:00',
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->paymentIntents = Mockery::mock();

    $stripe->paymentIntents->shouldReceive('retrieve')
        ->with('pi_broken', \Mockery::any())
        ->andThrow(new \Stripe\Exception\InvalidRequestException('No such payment_intent'));
    $stripe->paymentIntents->shouldReceive('retrieve')
        ->with('pi_good', \Mockery::any())
        ->andReturn(txns_pi('pi_good', 1100));

    $brand = Professional::find('pro-brand-e');
    $response = txns_makeController($stripe)->transactions(txns_makeRequest($brand, ['role' => 'brand']));

    $rows = $response->getData(true)['transactions'];
    expect($rows)->toHaveCount(1);
    expect($rows[0]['type'])->toBe('charge');
    expect($rows[0]['payout_id'])->toBe('payout-good');
});
