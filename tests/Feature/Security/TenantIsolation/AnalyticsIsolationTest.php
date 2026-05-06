<?php

use App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController;
use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupCommissionPayoutsTable();
    setupCommerceOrdersTables();  // also sets up brand_affiliate_rollup and order_items
    setupSiteVisitsTable();       // brand controller queries site_visits for page_views
    Cache::flush();
});

it('brand commerce analytics overview never exposes another brands revenue', function () {
    [$a, $b] = createTwoTenants('brand');
    $affiliateId = (string) Str::uuid(); // synthetic affiliate (FKs not enforced on SQLite)
    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    // Only Brand A has order data
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => (string) Str::uuid(),
        'shopify_order_id' => 'test-order-001',
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'brand_professional_id' => $a->id,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => 999_00,
        'refund_cents' => 0,
        'net_cents' => 900_00,
        'commission_cents' => 100_00,
        'commission_rate' => '0.1000',
        'rate_source' => 'brand_default',
        'currency_code' => 'GBP',
        'shopify_updated_at' => now()->toDateTimeString(),
        'occurred_at' => now()->subDay()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $req = tenantRequestAs($b);
    $req->query->set('from', $from);
    $req->query->set('to', $to);

    $response = app(BrandCommerceAnalyticsController::class)->overview($req);
    $payload = $response->getData(true);

    $totals = $payload['totals'] ?? [];
    expect((int) ($totals['gross_cents'] ?? 0))->toBe(0);
    expect((int) ($totals['orders_count'] ?? 0))->toBe(0);
});

it('affiliate commerce analytics overview never exposes another affiliates commissions', function () {
    [$a, $b] = createTwoTenants('affiliate');
    $brandId = (string) Str::uuid(); // synthetic brand (FKs not enforced on SQLite)
    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    // Only Affiliate A has order data
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => (string) Str::uuid(),
        'shopify_order_id' => 'test-order-002',
        'shopify_shop_domain' => 'brand-x.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $a->id,
        'status' => 'approved',
        'gross_cents' => 500_00,
        'refund_cents' => 0,
        'net_cents' => 450_00,
        'commission_cents' => 500_00,
        'commission_rate' => '0.1000',
        'rate_source' => 'brand_default',
        'currency_code' => 'GBP',
        'shopify_updated_at' => now()->toDateTimeString(),
        'occurred_at' => now()->subDay()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $req = tenantRequestAs($b);
    $req->query->set('from', $from);
    $req->query->set('to', $to);

    $response = app(AffiliateCommerceAnalyticsController::class)->overview($req);
    $payload = $response->getData(true);

    $totals = $payload['totals'] ?? [];
    expect((int) ($totals['commission_accrued_cents'] ?? 0))->toBe(0);
});
