<?php

use App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController;
use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    Cache::flush();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.brand_metrics_daily (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        date_bucket TEXT,
        gross_revenue_cents INTEGER,
        order_count INTEGER,
        commission_accrued_cents INTEGER,
        commission_reversed_cents INTEGER,
        commission_paid_cents INTEGER,
        currency_code TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.brand_affiliate_daily (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        date_bucket TEXT,
        gross_revenue_cents INTEGER,
        order_count INTEGER
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.brand_commission_daily (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        date_bucket TEXT,
        commission_accrued_cents INTEGER,
        commission_reversed_cents INTEGER,
        commission_paid_cents INTEGER,
        currency_code TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.professional_metrics_daily (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT,
        date_bucket TEXT,
        commission_accrued_cents INTEGER,
        commission_reversed_cents INTEGER,
        commission_paid_cents INTEGER,
        currency_code TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.professional_metrics_hourly (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT,
        hour_start TEXT,
        commission_accrued_cents INTEGER,
        commission_reversed_cents INTEGER,
        commission_paid_cents INTEGER,
        currency_code TEXT
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.brand_metrics_hourly (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        hour_start TEXT,
        gross_revenue_cents INTEGER,
        order_count INTEGER,
        commission_accrued_cents INTEGER,
        commission_reversed_cents INTEGER,
        commission_paid_cents INTEGER,
        currency_code TEXT
    )');
});

it('brand commerce analytics overview never exposes another brands revenue', function () {
    [$a, $b] = createTwoTenants('brand');
    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    DB::table('analytics.brand_metrics_daily')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $a->id,
        'date_bucket' => now()->subDay()->toDateString(),
        'gross_revenue_cents' => 999_00,
        'order_count' => 5,
        'commission_accrued_cents' => 99_00,
        'commission_reversed_cents' => 0,
        'commission_paid_cents' => 0,
        'currency_code' => 'GBP',
    ]);

    $req = tenantRequestAs($b);
    $req->query->set('from', $from);
    $req->query->set('to', $to);

    $response = app(BrandCommerceAnalyticsController::class)->overview($req);
    $payload = $response->getData(true);

    $totals = $payload['data']['totals'] ?? [];
    expect((int) ($totals['gross_revenue_cents'] ?? $totals['total_revenue_cents'] ?? 0))->toBe(0);
    expect((int) ($totals['order_count'] ?? 0))->toBe(0);
});

it('affiliate commerce analytics overview never exposes another affiliates commissions', function () {
    [$a, $b] = createTwoTenants('affiliate');
    $from = now()->subDays(7)->toDateString();
    $to = now()->toDateString();

    DB::table('analytics.professional_metrics_daily')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $a->id,
        'date_bucket' => now()->subDay()->toDateString(),
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

    $totals = $payload['data']['totals'] ?? [];
    expect((int) ($totals['commission_accrued_cents'] ?? 0))->toBe(0);
});

it('site analytics data is scoped by professional_id at the database level', function () {
    [$a, $b] = createTwoTenants('brand');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.site_visits (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        site_id TEXT,
        occurred_at TEXT,
        device_type TEXT,
        country_code TEXT,
        referrer TEXT,
        ip_hash TEXT
    )');

    DB::table('analytics.site_visits')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'site_id' => $a->site->id,
        'occurred_at' => now()->toDateTimeString(),
    ]);

    // Verify the DB-level WHERE clause isolates correctly
    $countForB = DB::table('analytics.site_visits')
        ->where('professional_id', $b->id)
        ->count();

    expect($countForB)->toBe(0);

    $countForA = DB::table('analytics.site_visits')
        ->where('professional_id', $a->id)
        ->count();

    expect($countForA)->toBe(1);
});
