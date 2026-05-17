<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffStripeConnectController;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupCommissionPayoutsTable();
});

function makeStaffStripeProfessional(string $type = 'brand'): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'stripe-'.substr($id, 0, 8),
        'handle_lc' => 'stripe-'.substr($id, 0, 8),
        'display_name' => 'Stripe Pro',
        'primary_email' => 'stripe-'.substr($id, 0, 8).'@example.com',
        'professional_type' => $type,
        'status' => 'active',
    ]);

    return Professional::query()->find($id);
}

it('returns an empty payment_methods list for non-brand professionals (no 403 leak)', function () {
    $pro = makeStaffStripeProfessional('professional');

    $connectService = Mockery::mock(StripeConnectService::class);
    $connectService->shouldNotReceive('listPaymentMethods');

    $controller = new StaffStripeConnectController($connectService);
    $response = $controller->paymentMethods($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['payment_methods'])->toBe([]);
});

it('delegates listPaymentMethods to StripeConnectService for brand professionals', function () {
    $pro = makeStaffStripeProfessional('brand');

    $connectService = Mockery::mock(StripeConnectService::class);
    $connectService->shouldReceive('listPaymentMethods')
        ->once()
        ->with(Mockery::on(fn (Professional $p) => $p->id === $pro->id))
        ->andReturn([
            ['id' => 'pm_test_1', 'card' => ['brand' => 'visa', 'last4' => '4242']],
        ]);

    $controller = new StaffStripeConnectController($connectService);
    $response = $controller->paymentMethods($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['payment_methods'])->toHaveCount(1)
        ->and($body['payment_methods'][0]['card']['last4'])->toBe('4242');
});

it('lists payouts where the inspected pro is either brand or affiliate', function () {
    $brandPro = makeStaffStripeProfessional('brand');
    $affiliatePro = makeStaffStripeProfessional('influencer');
    $otherBrand = makeStaffStripeProfessional('brand');

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
        [
            'id' => (string) Str::uuid(),
            'brand_professional_id' => $brandPro->id,
            'affiliate_professional_id' => $affiliatePro->id,
            'status' => 'completed',
            'net_payout_cents' => 8000,
            'currency_code' => 'AUD',
            'created_at' => now()->subDay()->toDateTimeString(),
            'updated_at' => now()->subDay()->toDateTimeString(),
        ],
        [
            'id' => (string) Str::uuid(),
            'brand_professional_id' => $otherBrand->id,
            'affiliate_professional_id' => (string) Str::uuid(),
            'status' => 'completed',
            'net_payout_cents' => 1234,
            'currency_code' => 'AUD',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
    ]);

    $connectService = Mockery::mock(StripeConnectService::class);
    $controller = new StaffStripeConnectController($connectService);

    // Brand-side view
    $response = $controller->payouts(Request::create('/', 'GET'), $brandPro);
    $body = $response->getData(true);

    expect($body['payouts'])->toHaveCount(1)
        ->and((int) $body['payouts'][0]['net_payout_cents'])->toBe(8000);

    // Affiliate-side view of the same row
    $response = $controller->payouts(Request::create('/', 'GET'), $affiliatePro);
    $body = $response->getData(true);

    expect($body['payouts'])->toHaveCount(1)
        ->and((int) $body['payouts'][0]['net_payout_cents'])->toBe(8000);
});

it('caps payouts at the requested limit', function () {
    $brandPro = makeStaffStripeProfessional('brand');
    $rows = [];
    for ($i = 0; $i < 5; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'brand_professional_id' => $brandPro->id,
            'affiliate_professional_id' => (string) Str::uuid(),
            'status' => 'completed',
            'net_payout_cents' => 100 + $i,
            'currency_code' => 'AUD',
            'created_at' => now()->subMinutes(5 - $i)->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];
    }
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert($rows);

    $controller = new StaffStripeConnectController(Mockery::mock(StripeConnectService::class));
    $response = $controller->payouts(Request::create('/?limit=2', 'GET'), $brandPro);
    $body = $response->getData(true);

    expect($body['payouts'])->toHaveCount(2);
});

// ── #PAYOUT-1 status() ───────────────────────────────────────────────────────

it('returns a curated status payload for a brand with active Stripe Connect', function () {
    $pro = makeStaffStripeProfessional('brand');
    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $pro->id)
        ->update([
            'stripe_connect_account_id' => 'acct_test_brand',
            'stripe_connect_status' => 'active',
            'stripe_card_payment_method_id' => 'pm_card_1',
            'stripe_card_brand' => 'visa',
            'stripe_card_last4' => '4242',
            'stripe_becs_payment_method_id' => 'pm_becs_1',
            'stripe_becs_bsb' => '062-000',
            'stripe_becs_last4' => '5678',
            'preferred_payout_method' => 'card',
            'stripe_commission_funding_mode' => 'auto_charge',
        ]);
    $pro->refresh();

    $connectService = Mockery::mock(StripeConnectService::class);
    $connectService->shouldReceive('syncAccountStatus')
        ->once()
        ->with(Mockery::on(fn (Professional $p) => $p->id === $pro->id))
        ->andReturn([
            'status' => 'active',
            'stripe_connect_account_id' => 'acct_test_brand',
            'card_payments_active' => true,
            'stripe_transfers_active' => true,
            // requirements come from extractRequirements — already a curated array of strings.
            'requirements' => [],
        ]);

    $controller = new StaffStripeConnectController($connectService);
    $response = $controller->status($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys($body))->toEqualCanonicalizing([
            'has_account',
            'status',
            'card_payments_active',
            'stripe_transfers_active',
            'requirements_summary',
            'payment_methods_count',
            'default_payment_method_last4',
            'funding_mode',
        ])
        ->and($body['has_account'])->toBeTrue()
        ->and($body['status'])->toBe('active')
        ->and($body['card_payments_active'])->toBeTrue()
        ->and($body['stripe_transfers_active'])->toBeTrue()
        ->and($body['requirements_summary'])->toBe([])
        ->and($body['payment_methods_count'])->toBe(2)
        // preferred=card → expose the card last4 as default
        ->and($body['default_payment_method_last4'])->toBe('4242')
        ->and($body['funding_mode'])->toBe('auto_charge');
});

it('returns a has_account=false skeleton when the professional has no Stripe account', function () {
    $pro = makeStaffStripeProfessional('brand');

    $connectService = Mockery::mock(StripeConnectService::class);
    $connectService->shouldReceive('syncAccountStatus')
        ->once()
        ->andReturn([
            'status' => 'not_connected',
            'stripe_connect_account_id' => null,
            'card_payments_active' => false,
            'stripe_transfers_active' => false,
            'requirements' => [],
        ]);

    $controller = new StaffStripeConnectController($connectService);
    $response = $controller->status($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['has_account'])->toBeFalse()
        ->and($body['status'])->toBe('not_connected')
        ->and($body['card_payments_active'])->toBeFalse()
        ->and($body['stripe_transfers_active'])->toBeFalse()
        ->and($body['requirements_summary'])->toBe([])
        ->and($body['payment_methods_count'])->toBe(0)
        ->and($body['default_payment_method_last4'])->toBeNull()
        // stripe_commission_funding_mode column has DEFAULT 'auto_charge', so even
        // a fresh row reports auto_charge — funding_mode is decoupled from has_account.
        ->and($body['funding_mode'])->toBe('auto_charge');
});

it('picks the becs last4 as default when preferred_payout_method=becs', function () {
    $pro = makeStaffStripeProfessional('brand');
    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $pro->id)
        ->update([
            'stripe_connect_account_id' => 'acct_test_becs',
            'stripe_connect_status' => 'active',
            'stripe_card_payment_method_id' => 'pm_card_1',
            'stripe_card_last4' => '4242',
            'stripe_becs_payment_method_id' => 'pm_becs_1',
            'stripe_becs_last4' => '5678',
            'preferred_payout_method' => 'becs',
            'stripe_commission_funding_mode' => 'manual_topup',
        ]);
    $pro->refresh();

    $connectService = Mockery::mock(StripeConnectService::class);
    $connectService->shouldReceive('syncAccountStatus')->once()->andReturn([
        'status' => 'active',
        'stripe_connect_account_id' => 'acct_test_becs',
        'card_payments_active' => true,
        'stripe_transfers_active' => true,
        'requirements' => [],
    ]);

    $controller = new StaffStripeConnectController($connectService);
    $body = $controller->status($pro)->getData(true);

    expect($body['payment_methods_count'])->toBe(2)
        ->and($body['default_payment_method_last4'])->toBe('5678')
        ->and($body['funding_mode'])->toBe('manual_topup');
});

it('exposes requirements_summary verbatim from the service (already curated)', function () {
    $pro = makeStaffStripeProfessional('brand');
    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $pro->id)
        ->update(['stripe_connect_account_id' => 'acct_restricted']);
    $pro->refresh();

    $connectService = Mockery::mock(StripeConnectService::class);
    $connectService->shouldReceive('syncAccountStatus')->once()->andReturn([
        'status' => 'restricted',
        'stripe_connect_account_id' => 'acct_restricted',
        'card_payments_active' => true,
        'stripe_transfers_active' => false,
        'requirements' => ['external_account', 'business_profile.url'],
    ]);

    $controller = new StaffStripeConnectController($connectService);
    $body = $controller->status($pro)->getData(true);

    expect($body['has_account'])->toBeTrue()
        ->and($body['status'])->toBe('restricted')
        ->and($body['requirements_summary'])->toBe(['external_account', 'business_profile.url']);
});
