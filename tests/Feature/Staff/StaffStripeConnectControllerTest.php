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
