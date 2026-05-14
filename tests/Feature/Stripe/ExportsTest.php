<?php

use App\Http\Controllers\Api\Professional\Stripe\StripeConnectController;
use App\Http\Requests\Api\Professional\Stripe\ExportsRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheLockService;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\ExportService;
use App\Services\Stripe\StripeBalanceService;
use App\Services\Stripe\StripeConnectService;
use App\Services\Stripe\StripeTransactionFetcher;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

// Phase 6 — GET /stripe/exports/{type}.{format}
// Exercises the export endpoint end-to-end: CSV streaming for payouts + detailed-commissions,
// XLSX format, EOFY date window, and cross-role rejection.
//
// Transactions export is light-touched (relies on the Stripe fetcher which has its own tests);
// the focus here is on the data shapes for the bookkeeping artifacts (payouts + detailed-commissions).

beforeEach(function () {
    setupProfessionalsTable();
    setupCommerceOrdersTables();

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

function exp_seedPro(string $id, string $type, ?string $handle = null, string $name = 'Test'): Professional
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => $handle ?? $id,
        'handle_lc' => $handle ?? $id,
        'display_name' => $name,
        'professional_type' => $type,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($id);
}

function exp_seedPayout(string $id, string $brandId, string $affiliateId, array $overrides = []): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert(array_merge([
        'id' => $id,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'completed',
        'gross_commission_cents' => 2000,
        'platform_fee_cents' => 200,
        'net_payout_cents' => 1800,
        'currency_code' => 'AUD',
        'ledger_entry_count' => 2,
        'payment_intent_id' => 'pi_'.$id,
        'charge_id' => 'ch_'.$id,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function exp_seedOrder(string $id, string $brandId, string $affiliateId, string $payoutId, array $overrides = []): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('commerce.orders')->insert(array_merge([
        'id' => $id,
        'shopify_order_id' => 'shop_'.$id,
        'shopify_shop_domain' => 'test.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => 10000,
        'commission_cents' => 1000,
        'refund_cents' => 0,
        'net_cents' => 10000,
        'commission_rate' => 10,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'payout_id' => $payoutId,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));
}

function exp_makeController(): StripeConnectController
{
    $stripeMock = Mockery::mock(StripeClient::class);
    $fetcher = new StripeTransactionFetcher($stripeMock);
    $balance = Mockery::mock(StripeBalanceService::class);
    $exports = new ExportService($fetcher);

    return new StripeConnectController(
        Mockery::mock(StripeConnectService::class),
        Mockery::mock(CommissionPayoutService::class),
        $fetcher,
        $balance,
        $exports,
        app(CacheLockService::class),
    );
}

function exp_makeRequest(Professional $pro, array $query): ExportsRequest
{
    $request = ExportsRequest::create('/api/stripe/exports/test.csv', 'GET', $query);
    $request->attributes->set('professional', $pro);

    return $request;
}

it('payouts export streams CSV with headers + rows', function () {
    $brand = exp_seedPro('exp-brand-1', 'brand', 'expbrand1', 'BrandCo');
    $aff = exp_seedPro('exp-aff-1', 'influencer', 'expaff1', 'AffOne');

    exp_seedPayout('pay-e1', $brand->id, $aff->id);
    exp_seedPayout('pay-e2', $brand->id, $aff->id, ['ledger_entry_count' => 5]);

    $response = exp_makeController()->export(
        exp_makeRequest($brand, ['role' => 'brand']),
        'payouts',
        'csv',
    );

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toContain('date,status,orders_count');
    expect($body)->toContain('completed');
    // Two payouts → two data rows + header = 3 lines total
    expect(substr_count($body, "\n"))->toBeGreaterThanOrEqual(3);
});

it('detailed-commissions export streams one row per linked order', function () {
    $brand = exp_seedPro('exp-brand-2', 'brand', 'expbrand2', 'BrandTwo');
    $aff = exp_seedPro('exp-aff-2', 'influencer', 'expaff2', 'AffTwo');

    exp_seedPayout('pay-d1', $brand->id, $aff->id);
    exp_seedOrder('ord-d1', $brand->id, $aff->id, 'pay-d1');
    exp_seedOrder('ord-d2', $brand->id, $aff->id, 'pay-d1');

    $response = exp_makeController()->export(
        exp_makeRequest($brand, ['role' => 'brand']),
        'detailed-commissions',
        'csv',
    );

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toContain('payout_id,payout_status');
    expect($body)->toContain('ord-d1');
    expect($body)->toContain('ord-d2');
    expect($body)->toContain('BrandTwo');
    expect($body)->toContain('AffTwo');
    expect($body)->toContain('gst_estimate_cents');
});

it('eofy export filters orders to AU FY (Jul 1 → Jun 30)', function () {
    $brand = exp_seedPro('exp-brand-fy', 'brand', 'expbrandfy', 'BrandFY');
    $aff = exp_seedPro('exp-aff-fy', 'influencer', 'expafffy', 'AffFY');

    exp_seedPayout('pay-fy-in', $brand->id, $aff->id, ['created_at' => '2025-09-15 10:00:00']);
    exp_seedPayout('pay-fy-out', $brand->id, $aff->id, ['created_at' => '2025-05-15 10:00:00']);

    exp_seedOrder('ord-fy-in', $brand->id, $aff->id, 'pay-fy-in', ['occurred_at' => '2025-09-15 10:00:00']);
    exp_seedOrder('ord-fy-out', $brand->id, $aff->id, 'pay-fy-out', ['occurred_at' => '2025-05-15 10:00:00']);

    $response = exp_makeController()->export(
        exp_makeRequest($brand, ['role' => 'brand', 'fy' => 2026]),
        'eofy',
        'csv',
    );

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toContain('ord-fy-in');
    expect($body)->not->toContain('ord-fy-out');
});

it('export denies cross-role calls', function () {
    $brand = exp_seedPro('exp-brand-x', 'brand', 'expbrandx', 'CrossBrand');

    expect(fn () => exp_makeController()->export(
        exp_makeRequest($brand, ['role' => 'affiliate']),
        'payouts',
        'csv',
    ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('export rejects invalid type', function () {
    $brand = exp_seedPro('exp-brand-i', 'brand', 'expbrandi', 'InvalidType');

    $response = exp_makeController()->export(
        exp_makeRequest($brand, ['role' => 'brand']),
        'gibberish',
        'csv',
    );

    expect($response->status())->toBe(422);
});

it('xlsx export returns binary content with the correct content-type', function () {
    $brand = exp_seedPro('exp-brand-x2', 'brand', 'expbrandx2', 'XlsxBrand');
    $aff = exp_seedPro('exp-aff-x2', 'influencer', 'expaffx2', 'XlsxAff');
    exp_seedPayout('pay-xlsx', $brand->id, $aff->id);

    $response = exp_makeController()->export(
        exp_makeRequest($brand, ['role' => 'brand']),
        'payouts',
        'xlsx',
    );

    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    // XLSX is a zip file — first bytes are "PK"
    $body = $response->getContent();
    expect(substr((string) $body, 0, 2))->toBe('PK');
});
