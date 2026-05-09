<?php

use App\Http\Controllers\Api\Professional\Analytics\AffiliateCommerceAnalyticsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ensure that commission_paid_cents only counts orders whose linked payout
 * has status='completed'.
 *
 * Previously any non-null payout_id was treated as "paid"; this tightens the
 * gate so in-flight payouts (transferring, pending, etc.) do not inflate the metric.
 */

beforeEach(function () {
    setupProfessionalsTable();
    setupCommerceOrdersTables();
    setupCommissionPayoutsTable();
    Cache::flush();

    $this->controller = app(AffiliateCommerceAnalyticsController::class);
});

/**
 * Build a request with the professional injected into attributes — mirrors
 * what LoadCurrentProfessional middleware does in production.
 */
function makeAnalyticsRequest(\App\Models\Core\Professional\Professional $aff, string $from, string $to): Request
{
    $request = Request::create(
        "/api/affiliate/commerce-analytics?from={$from}&to={$to}",
        'GET',
        ['from' => $from, 'to' => $to]
    );
    $request->attributes->set('professional', $aff);
    $request->attributes->set('supabase_uid', $aff->auth_user_id ?? (string) Str::uuid());

    return $request;
}

/**
 * Insert a payout row directly and return the ID.
 */
function insertTestPayout(string $brandId, string $affiliateId, string $status): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('commerce.commission_payouts')->insert([
        'id'                        => $id,
        'brand_professional_id'     => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status'                    => $status,
        'net_payout_cents'          => 5000,
        'currency_code'             => 'AUD',
        'created_at'                => $now,
        'updated_at'                => $now,
    ]);

    return $id;
}

/**
 * Insert an order row linked to a payout and return the ID.
 */
function insertTestOrder(string $brandId, string $affiliateId, string $payoutId, int $commissionCents = 5000): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('commerce.orders')->insert([
        'id'                        => $id,
        'shopify_order_id'          => 'order-' . Str::random(8),
        'shopify_shop_domain'       => 'test-shop.myshopify.com',
        'brand_professional_id'     => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status'                    => 'approved',
        'gross_cents'               => $commissionCents,
        'discount_cents'            => 0,
        'refund_cents'              => 0,
        'net_cents'                 => $commissionCents,
        'commission_cents'          => $commissionCents,
        'commission_rate'           => 0.10,
        'rate_source'               => 'brand_default',
        'currency_code'             => 'AUD',
        'line_items'                => '[]',
        'shopify_data'              => '{}',
        'payout_id'                 => $payoutId,
        'shopify_updated_at'        => $now,
        'occurred_at'               => now()->subDay()->toDateTimeString(),
        'created_at'                => $now,
        'updated_at'                => $now,
    ]);

    return $id;
}

it('does NOT count orders linked to a non-completed payout as paid', function () {
    $aff = createTenant('aff-paid-gate-1', 'affiliate');
    $brand = createTenant('brand-paid-gate-1', 'brand');

    $payoutId = insertTestPayout($brand->id, $aff->id, 'transferring');
    insertTestOrder($brand->id, $aff->id, $payoutId);

    $from = now()->subWeek()->toDateString();
    $to = now()->toDateString();

    $response = $this->controller->overview(makeAnalyticsRequest($aff, $from, $to));
    $data = json_decode($response->getContent(), true);

    expect($data['totals']['commission_paid_cents'])->toBe(0);
});

it('counts orders linked to a completed payout as paid', function () {
    $aff = createTenant('aff-paid-gate-2', 'affiliate');
    $brand = createTenant('brand-paid-gate-2', 'brand');

    $payoutId = insertTestPayout($brand->id, $aff->id, 'completed');
    insertTestOrder($brand->id, $aff->id, $payoutId, 5000);

    $from = now()->subWeek()->toDateString();
    $to = now()->toDateString();

    $response = $this->controller->overview(makeAnalyticsRequest($aff, $from, $to));
    $data = json_decode($response->getContent(), true);

    expect($data['totals']['commission_paid_cents'])->toBe(5000);
});
