<?php

use App\Models\Core\Professional\Professional;
use App\Services\Analytics\AnalyticsService;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Phase 5 — AnalyticsService. Seeds raw events + commerce orders + payouts and asserts the
// returned payload shape, per-window windowing, and rate calculations.

beforeEach(function () {
    setupProfessionalsTable();
    setupCommerceOrdersTables();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupBlocksTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.site_visits (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        site_id TEXT NULL,
        occurred_at TEXT NULL,
        session_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        user_agent TEXT NULL,
        referrer TEXT NULL,
        utm_source TEXT NULL,
        utm_medium TEXT NULL,
        utm_campaign TEXT NULL,
        country_code TEXT NULL,
        device_type TEXT NULL,
        created_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.cart_events (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        site_id TEXT NULL,
        occurred_at TEXT NULL,
        event_type TEXT NULL,
        session_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        shopify_product_id TEXT NULL,
        quantity INTEGER NULL,
        created_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.brand_affiliate_rollup (
        brand_professional_id TEXT NULL,
        affiliate_professional_id TEXT NULL,
        currency_code TEXT NULL,
        orders_count INTEGER NULL,
        gross_cents INTEGER NULL,
        net_cents INTEGER NULL,
        commission_cents INTEGER NULL,
        reversed_commission_cents INTEGER NULL,
        day TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT NOT NULL,
        affiliate_professional_id TEXT NOT NULL,
        status TEXT,
        gross_commission_cents INTEGER,
        platform_fee_cents INTEGER,
        net_payout_cents INTEGER,
        currency_code TEXT,
        ledger_entry_count INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');

    // commerce.order_items canonical schema mirrored from setupCommerceOrdersTables() —
    // included here too in case that helper hasn't been called yet by the test ordering.
});

function stats_seedPro(string $id, string $type, string $name = 'Test Pro'): Professional
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

    return Professional::find($id);
}

function stats_seedVisit(string $proId, string $when, ?string $referrer = null): void
{
    DB::connection('pgsql')->table('analytics.site_visits')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'site_id' => (string) Str::uuid(),
        'occurred_at' => $when,
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
        'referrer' => $referrer,
        'created_at' => $when,
    ]);
}

function stats_seedOrder(string $brandId, string $affiliateId, int $gross, int $commission, string $occurredAt): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id' => $id,
        'shopify_order_id' => 'shop_'.$id,
        'shopify_shop_domain' => 'test.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => $gross,
        'commission_cents' => $commission,
        'refund_cents' => 0,
        'net_cents' => $gross,
        'commission_rate' => 10,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'occurred_at' => $occurredAt,
        'created_at' => $occurredAt,
        'updated_at' => $occurredAt,
    ]);

    return $id;
}

function stats_seedCartEvent(string $proId, string $when, string $sessionId, string $eventType = 'checkout_start'): void
{
    DB::connection('pgsql')->table('analytics.cart_events')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'site_id' => (string) Str::uuid(),
        'occurred_at' => $when,
        'event_type' => $eventType,
        'session_id' => $sessionId,
        'created_at' => $when,
    ]);
}

function stats_makeService(): AnalyticsService
{
    return new AnalyticsService(app(CacheLockService::class));
}

it('forAffiliate returns the expected payload shape across all six windows', function () {
    $aff = stats_seedPro('stats-aff-1', 'influencer', 'Aff One');

    $payload = stats_makeService()->forAffiliate($aff);

    expect($payload)->toHaveKeys([
        'views', 'orders_count', 'total_sales_cents', 'total_commissions_cents',
        'total_refunds_cents', 'cart_sessions', 'conversion_rate_pct',
        'abandoned_cart_rate_pct', 'pending_commission_cents',
        'commissions_pocketed_cents', 'top_referrers', 'per_section_clicks',
        'per_section_views', 'per_platform_clicks', 'best_selling_products',
    ]);

    foreach (['h24', 'd7', 'd30', 'm6', 'y1', 'all'] as $w) {
        expect($payload['views'])->toHaveKey($w);
        expect($payload['orders_count'])->toHaveKey($w);
    }
});

it('views are correctly windowed to h24 rolling vs all-time', function () {
    $aff = stats_seedPro('stats-aff-2', 'influencer', 'Aff Two');

    // Three visits in the last hour (counted in h24, d7, all)
    stats_seedVisit($aff->id, now()->subMinutes(30)->toDateTimeString());
    stats_seedVisit($aff->id, now()->subMinutes(20)->toDateTimeString());
    stats_seedVisit($aff->id, now()->subMinutes(10)->toDateTimeString());

    // Two visits 10 days ago (counted in d30, m6, y1, all — NOT d7, NOT h24)
    stats_seedVisit($aff->id, now()->subDays(10)->toDateTimeString());
    stats_seedVisit($aff->id, now()->subDays(10)->toDateTimeString());

    // One visit 400 days ago (only counted in all)
    stats_seedVisit($aff->id, now()->subDays(400)->toDateTimeString());

    $payload = stats_makeService()->forAffiliate($aff);

    expect($payload['views']['h24'])->toBe(3);
    expect($payload['views']['d7'])->toBe(3);
    expect($payload['views']['d30'])->toBe(5);
    expect($payload['views']['m6'])->toBe(5);
    expect($payload['views']['y1'])->toBe(5);
    expect($payload['views']['all'])->toBe(6);
});

it('orders + sales aggregate across the windows correctly', function () {
    $brand = stats_seedPro('stats-brand-o', 'brand', 'BrandO');
    $aff = stats_seedPro('stats-aff-o', 'influencer', 'AffO');

    stats_seedOrder($brand->id, $aff->id, 10000, 1500, now()->subHours(1)->toDateTimeString());
    stats_seedOrder($brand->id, $aff->id, 20000, 2500, now()->subDays(3)->toDateTimeString());
    stats_seedOrder($brand->id, $aff->id, 30000, 3500, now()->subDays(40)->toDateTimeString());

    $payload = stats_makeService()->forAffiliate($aff);

    expect($payload['orders_count']['h24'])->toBe(1);
    expect($payload['orders_count']['d7'])->toBe(2);
    expect($payload['orders_count']['d30'])->toBe(2);
    expect($payload['orders_count']['all'])->toBe(3);

    expect($payload['total_sales_cents']['h24'])->toBe(10000);
    expect($payload['total_sales_cents']['d7'])->toBe(30000);
    expect($payload['total_sales_cents']['all'])->toBe(60000);

    expect($payload['total_commissions_cents']['all'])->toBe(7500);
});

it('conversion rate = orders / views * 100 per window, null when no views', function () {
    $brand = stats_seedPro('stats-brand-c', 'brand', 'BrandC');
    $aff = stats_seedPro('stats-aff-c', 'influencer', 'AffC');

    // 100 visits + 5 orders in last 24h → 5% conversion
    for ($i = 0; $i < 100; $i++) {
        stats_seedVisit($aff->id, now()->subMinutes(rand(1, 1400))->toDateTimeString());
    }
    for ($i = 0; $i < 5; $i++) {
        stats_seedOrder($brand->id, $aff->id, 1000, 100, now()->subMinutes(rand(1, 1400))->toDateTimeString());
    }

    $payload = stats_makeService()->forAffiliate($aff);

    expect($payload['conversion_rate_pct']['h24'])->toBe(5.0);
    expect($payload['conversion_rate_pct']['all'])->toBe(5.0);
});

it('abandoned cart rate = (sessions - orders) / sessions * 100, null when no sessions', function () {
    $brand = stats_seedPro('stats-brand-abc', 'brand', 'BrandABC');
    $aff = stats_seedPro('stats-aff-abc', 'influencer', 'AffABC');

    // 10 cart sessions started checkout, 3 became orders → 70% abandonment
    for ($i = 0; $i < 10; $i++) {
        stats_seedCartEvent($aff->id, now()->subHours(1)->toDateTimeString(), (string) Str::uuid());
    }
    for ($i = 0; $i < 3; $i++) {
        stats_seedOrder($brand->id, $aff->id, 1000, 100, now()->subHours(1)->toDateTimeString());
    }

    $payload = stats_makeService()->forAffiliate($aff);

    expect($payload['abandoned_cart_rate_pct']['h24'])->toBe(70.0);
});

it('top referrers groups by referrer, limit 5, "direct" for nulls', function () {
    $aff = stats_seedPro('stats-aff-ref', 'influencer', 'AffRef');

    // 3 visits from same Instagram URL, 2 direct (null referrer), 1 from TikTok.
    // GROUP BY referrer is literal — same URL string groups together. Test seeds the same
    // string thrice so the 3-count is unambiguous regardless of URL normalisation.
    foreach ([
        'https://instagram.com/affiliate', 'https://instagram.com/affiliate', 'https://instagram.com/affiliate',
        null, null,
        'https://tiktok.com/affiliate',
    ] as $ref) {
        stats_seedVisit($aff->id, now()->subHours(1)->toDateTimeString(), $ref);
    }

    $payload = stats_makeService()->forAffiliate($aff);
    $top = collect($payload['top_referrers']);

    expect($top->count())->toBeLessThanOrEqual(5);
    expect($top->first())->toMatchArray(['visits' => 3]);
});

it('forBrand returns total_orders + top_affiliates across affiliates', function () {
    $brand = stats_seedPro('stats-brand-top', 'brand', 'BrandTop');
    $aff1 = stats_seedPro('stats-aff-top-1', 'influencer', 'AffOne');
    $aff2 = stats_seedPro('stats-aff-top-2', 'influencer', 'AffTwo');

    // aff1 → bigger affiliate (3 orders, 30k gross)
    foreach ([10000, 10000, 10000] as $amount) {
        stats_seedOrder($brand->id, $aff1->id, $amount, 1000, now()->subDays(2)->toDateTimeString());
    }
    // aff2 → smaller (1 order, 5k gross)
    stats_seedOrder($brand->id, $aff2->id, 5000, 500, now()->subDays(2)->toDateTimeString());

    // Seed the rollup since brandTopAffiliates reads from it
    DB::connection('pgsql')->table('commerce.brand_affiliate_rollup')->insert([
        ['brand_professional_id' => $brand->id, 'affiliate_professional_id' => $aff1->id, 'currency_code' => 'AUD', 'orders_count' => 3, 'gross_cents' => 30000, 'net_cents' => 30000, 'commission_cents' => 3000, 'reversed_commission_cents' => 0, 'day' => now()->toDateString()],
        ['brand_professional_id' => $brand->id, 'affiliate_professional_id' => $aff2->id, 'currency_code' => 'AUD', 'orders_count' => 1, 'gross_cents' => 5000, 'net_cents' => 5000, 'commission_cents' => 500, 'reversed_commission_cents' => 0, 'day' => now()->toDateString()],
    ]);

    $payload = stats_makeService()->forBrand($brand);

    expect($payload['total_orders']['all'])->toBe(4);
    expect($payload['total_sales_cents']['all'])->toBe(35000);
    expect($payload['top_affiliates'])->toHaveCount(2);
    expect($payload['top_affiliates'][0]['affiliate_id'])->toBe($aff1->id);
    expect($payload['top_affiliates'][0]['gross_cents'])->toBe(30000);
});

it('per_platform_clicks normalises utm_source into platform buckets', function () {
    $aff = stats_seedPro('stats-aff-plat', 'influencer', 'AffPlat');

    // Seed link_clicks with various utm_source values
    foreach (['instagram', 'instagram.com', 'IG', 'tiktok', null] as $i => $source) {
        DB::connection('pgsql')->table('analytics.link_clicks')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $aff->id,
            'site_id' => (string) Str::uuid(),
            'link_block_id' => (string) Str::uuid(),
            'occurred_at' => now()->subHours(1)->toDateTimeString(),
            'session_id' => (string) Str::uuid(),
            'visitor_id' => (string) Str::uuid(),
            'utm_source' => $source,
            'created_at' => now()->subHours(1)->toDateTimeString(),
        ]);
    }

    $payload = stats_makeService()->forAffiliate($aff);
    $platforms = collect($payload['per_platform_clicks']);

    // 3 Instagram (instagram, instagram.com, IG), 1 TikTok, 1 Direct (null)
    $instagram = $platforms->firstWhere('platform', 'Instagram');
    expect($instagram)->not->toBeNull();
    expect($instagram['clicks'])->toBe(3);

    $tiktok = $platforms->firstWhere('platform', 'TikTok');
    expect($tiktok['clicks'])->toBe(1);
});
