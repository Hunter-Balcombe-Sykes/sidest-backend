<?php

// Phase 4 rewritten-caller equivalence test for BrandAffiliateController::snapshot.
// The Phase 3 cutover replaced reads of analytics.brand_affiliate_daily and
// analytics.site_metrics_daily with live queries against
// commerce.brand_affiliate_rollup, commerce.orders, and analytics.site_visits.
// This test seeds the new tables and asserts the response keeps the same
// shape and semantics the dashboard modal already depends on.

use App\Http\Controllers\Api\Professional\BrandAffiliateController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();
    setupBrandLinkTables();
    setupCommerceOrdersTables();
    setupSiteVisitsTable();
    setupCommissionPayoutsTable();
    Cache::flush();
});

function snapshotSeedTenants(): array
{
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        [
            'id' => $brandId,
            'handle' => 'brand-x',
            'handle_lc' => 'brand-x',
            'display_name' => 'Brand X',
            'first_name' => 'Brand',
            'last_name' => 'X',
            'primary_email' => 'brand@example.test',
            'professional_type' => 'brand',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => $affiliateId,
            'handle' => 'aff-y',
            'handle_lc' => 'aff-y',
            'display_name' => 'Affiliate Y',
            'first_name' => 'Aff',
            'last_name' => 'Y',
            'primary_email' => 'aff@example.test',
            'professional_type' => 'professional',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'slot' => 0,
        'custom_photos_enabled' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$brandId, $affiliateId];
}

function snapshotMakeRequest(string $brandId): Request
{
    // Professional::id isn't in $fillable, so `new Professional(['id' => ...])`
    // leaves id null. Load the persisted model so the controller's
    // $brandId = $professional->id resolves correctly.
    $brand = Professional::query()->whereKey($brandId)->first()
        ?? (new Professional)->forceFill(['id' => $brandId, 'professional_type' => 'brand']);

    $request = Request::create('/test', 'GET');
    $request->attributes->set('professional', $brand);

    return $request;
}

it('returns the modal snapshot shape from commerce.orders + rollup + site_visits', function () {
    [$brandId, $affiliateId] = snapshotSeedTenants();
    $now = now()->toDateTimeString();
    $occurredAt = now()->subDays(2)->toDateTimeString();

    // Two approved orders + one refunded order. Refunded must be excluded
    // from totals/customers to match the Phase 3 EXCLUDED_STATUSES contract.
    DB::connection('pgsql')->table('commerce.orders')->insert([
        [
            'shopify_order_id' => 'o1',
            'shopify_shop_domain' => 's.test',
            'brand_professional_id' => $brandId,
            'affiliate_professional_id' => $affiliateId,
            'customer_id' => (string) Str::uuid(),
            'status' => 'approved',
            'gross_cents' => 10000,
            'net_cents' => 10000,
            'commission_cents' => 1000,
            'currency_code' => 'AUD',
            'shopify_updated_at' => $occurredAt,
            'occurred_at' => $occurredAt,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'shopify_order_id' => 'o2',
            'shopify_shop_domain' => 's.test',
            'brand_professional_id' => $brandId,
            'affiliate_professional_id' => $affiliateId,
            'customer_id' => (string) Str::uuid(),
            'status' => 'approved',
            'gross_cents' => 5000,
            'net_cents' => 5000,
            'commission_cents' => 500,
            'currency_code' => 'AUD',
            'shopify_updated_at' => $occurredAt,
            'occurred_at' => $occurredAt,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'shopify_order_id' => 'o3',
            'shopify_shop_domain' => 's.test',
            'brand_professional_id' => $brandId,
            'affiliate_professional_id' => $affiliateId,
            'customer_id' => (string) Str::uuid(),
            'status' => 'refunded',
            'gross_cents' => 7777,
            'net_cents' => 0,
            'commission_cents' => 0,
            'currency_code' => 'AUD',
            'shopify_updated_at' => $occurredAt,
            'occurred_at' => $occurredAt,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    // Rollup row reflects the two non-refunded orders' lifetime totals.
    DB::connection('pgsql')->table('commerce.brand_affiliate_rollup')->insert([
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'day' => now()->subDays(2)->toDateString(),
        'currency_code' => 'AUD',
        'orders_count' => 2,
        'gross_cents' => 15000,
        'refund_cents' => 0,
        'net_cents' => 15000,
        'commission_cents' => 1500,
        'reversed_commission_cents' => 0,
        'updated_at' => $now,
    ]);

    // 4 site_visits, 3 unique visitors — drives visits_count, unique_visitors,
    // and conversion_rate_percent (orders_count=2 / unique=3 = 66.67%).
    $visitorA = (string) Str::uuid();
    $visitorB = (string) Str::uuid();
    $visitorC = (string) Str::uuid();
    foreach ([$visitorA, $visitorA, $visitorB, $visitorC] as $idx => $vid) {
        DB::connection('pgsql')->table('analytics.site_visits')->insert([
            'id' => (string) Str::uuid(),
            'professional_id' => $affiliateId,
            'site_id' => (string) Str::uuid(),
            'visitor_id' => $vid,
            'occurred_at' => $occurredAt,
            'created_at' => $now,
        ]);
    }

    // Commission payouts: one completed (paid_cents), one pending, one failed (voided_cents).
    // Every row carries the same column set (processed_at NULL where not applicable)
    // because Laravel's batch insert requires uniform keys.
    DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'affiliate_professional_id' => $affiliateId, 'status' => 'completed', 'net_payout_cents' => 1500, 'currency_code' => 'AUD', 'processed_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'affiliate_professional_id' => $affiliateId, 'status' => 'pending', 'net_payout_cents' => 250, 'currency_code' => 'AUD', 'processed_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'affiliate_professional_id' => $affiliateId, 'status' => 'failed', 'net_payout_cents' => 100, 'currency_code' => 'AUD', 'processed_at' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $controller = app(BrandAffiliateController::class);
    $response = $controller->snapshot(snapshotMakeRequest($brandId), $affiliateId);

    expect($response->status())->toBe(200);
    $data = json_decode($response->getContent(), true);

    expect($data['affiliate_id'])->toBe($affiliateId)
        ->and($data['currency_code'])->toBe('AUD')
        ->and($data['identity']['handle'])->toBe('aff-y')
        ->and($data['identity']['professional_type'])->toBe('professional');

    // Totals: 2 approved orders (refunded excluded), 15000 gross, 15000 net,
    // 1500 commission_net, 2 distinct customers.
    expect($data['totals'])->toBe([
        'orders_count' => 2,
        'gross_cents' => 15000,
        'net_cents' => 15000,
        'commission_net_cents' => 1500,
        'customers_count' => 2,
    ]);

    // Page views: 4 total, 3 unique → conv = 2/3 = 66.67%.
    expect($data['page_views']['visits_count'])->toBe(4)
        ->and($data['page_views']['unique_visitors'])->toBe(3)
        ->and($data['page_views']['conversion_rate_percent'])->toBe(66.67);

    expect($data['commission'])->toBe([
        'pending_cents' => 250,
        'paid_cents' => 1500,
        'voided_cents' => 100,
    ]);

    expect(count($data['recent_payouts']))->toBe(3);
});

it('returns 404 when the affiliate is not linked to this brand', function () {
    [$brandId] = snapshotSeedTenants();
    $unlinkedAffiliate = (string) Str::uuid();

    $controller = app(BrandAffiliateController::class);
    $response = $controller->snapshot(snapshotMakeRequest($brandId), $unlinkedAffiliate);

    expect($response->status())->toBe(404);
});

// Role gating now lives in `brand.only` middleware; EnsureBrandAccountTest covers it.
