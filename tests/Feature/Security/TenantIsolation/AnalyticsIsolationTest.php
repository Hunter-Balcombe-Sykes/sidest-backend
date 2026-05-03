<?php

use App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController;
use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupCommissionPayoutsTable();
    Cache::flush();

    // Column names must match what the controllers actually query (day, gross_cents, orders_count).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.brand_metrics_daily (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        day TEXT,
        orders_count INTEGER,
        gross_cents INTEGER,
        refunded_cents INTEGER,
        net_cents INTEGER,
        currency_code TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.brand_affiliate_daily (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        day TEXT,
        orders_count INTEGER,
        gross_cents INTEGER,
        net_cents INTEGER,
        commission_accrued_cents INTEGER,
        commission_net_cents INTEGER,
        customers_count INTEGER,
        currency_code TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.brand_commission_daily (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        day TEXT,
        payout_status TEXT,
        net_outstanding_cents INTEGER,
        payout_cents INTEGER,
        reversal_cents INTEGER,
        currency_code TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.professional_metrics_daily (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT,
        day TEXT,
        orders_count INTEGER,
        gross_cents INTEGER,
        refunded_cents INTEGER,
        net_cents INTEGER,
        commission_accrued_cents INTEGER,
        commission_reversed_cents INTEGER,
        commission_paid_cents INTEGER,
        currency_code TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.professional_metrics_hourly (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT,
        hour_start TEXT,
        orders_count INTEGER,
        gross_cents INTEGER,
        refunded_cents INTEGER,
        net_cents INTEGER,
        commission_accrued_cents INTEGER,
        commission_reversed_cents INTEGER,
        commission_paid_cents INTEGER,
        currency_code TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.brand_metrics_hourly (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        hour_start TEXT,
        orders_count INTEGER,
        gross_cents INTEGER,
        refunded_cents INTEGER,
        net_cents INTEGER,
        currency_code TEXT
    )');
});

it('brand commerce analytics overview never exposes another brands revenue', function () {
    [$a, $b] = createTwoTenants('brand');
    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    // Only Brand A has revenue data.
    DB::table('analytics.brand_metrics_daily')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $a->id,
        'day' => now()->subDay()->toDateString(),
        'orders_count' => 5,
        'gross_cents' => 999_00,
        'refunded_cents' => 0,
        'net_cents' => 900_00,
        'currency_code' => 'GBP',
    ]);

    $req = tenantRequestAs($b);
    $req->query->set('from', $from);
    $req->query->set('to', $to);

    $response = app(BrandCommerceAnalyticsController::class)->overview($req);
    $payload = $response->getData(true);

    // success() wraps via response()->json($data) — totals is a top-level key, no 'data' envelope.
    $totals = $payload['totals'] ?? [];
    expect((int) ($totals['gross_cents'] ?? 0))->toBe(0);
    expect((int) ($totals['orders_count'] ?? 0))->toBe(0);
});

it('affiliate commerce analytics overview never exposes another affiliates commissions', function () {
    [$a, $b] = createTwoTenants('affiliate');
    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    // Only Affiliate A has commission data.
    DB::table('analytics.professional_metrics_daily')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $a->id,
        'day' => now()->subDay()->toDateString(),
        'orders_count' => 3,
        'gross_cents' => 500_00,
        'refunded_cents' => 0,
        'net_cents' => 450_00,
        'commission_accrued_cents' => 500_00,
        'commission_reversed_cents' => 0,
        'commission_paid_cents' => 0,
        'currency_code' => 'GBP',
    ]);

    $req = tenantRequestAs($b);
    $req->query->set('from', $from);
    $req->query->set('to', $to);

    $response = app(AffiliateCommerceAnalyticsController::class)->overview($req);
    $payload = $response->getData(true);

    // success() wraps via response()->json($data) — totals is a top-level key, no 'data' envelope.
    $totals = $payload['totals'] ?? [];
    expect((int) ($totals['commission_accrued_cents'] ?? 0))->toBe(0);
});
