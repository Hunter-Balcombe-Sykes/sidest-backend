<?php

use App\Http\Controllers\Api\Internal\EmbeddedOrderAnalyticsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Read-only controller backing the Shopify admin order-block UI extension.
// These tests cover the deriveLineStatus 4-way branch table, the GID-prefix
// stripping, and the has_affiliate:false short-circuit. Direct controller
// calls — bypassing the JWT middleware exercised in EmbeddedConnectControllerTest.

beforeEach(function () {
    setupCommerceOrdersTables();
    setupProfessionalsTable();

    $this->controller = app(EmbeddedOrderAnalyticsController::class);
    $this->brandId = (string) Str::uuid();
    $this->affiliateId = (string) Str::uuid();

    // The controller eager-loads `affiliateProfessional`; we always need the row.
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $this->affiliateId,
        'display_name' => 'Affiliate One',
        'handle' => 'affiliate-one',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
});

function seedOrderRow(string $brandId, string $affiliateId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('commerce.orders')->insert(array_merge([
        'id' => $id,
        'shopify_order_id' => '12345',
        'shopify_shop_domain' => 'shop.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'paid',
        'gross_cents' => 10000,
        'net_cents' => 10000,
        'refund_cents' => 0,
        'commission_cents' => 1000,
        'commission_rate' => 0.10,
        'currency_code' => 'AUD',
        'payout_id' => null,
        'occurred_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

function seedOrderItem(string $orderId, string $brandId, string $affiliateId, array $overrides = []): void
{
    DB::connection('pgsql')->table('commerce.order_items')->insert(array_merge([
        'order_id' => $orderId,
        'shopify_line_item_id' => (string) Str::uuid(),
        'shopify_product_id' => '99887',
        'shopify_variant_id' => '99887-v1',
        'title' => 'Test Product',
        'quantity' => 1,
        'line_total_cents' => 10000,
        'commission_cents' => 1000,
        'commission_rate' => 0.10,
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'currency_code' => 'AUD',
        'occurred_at' => now()->toDateTimeString(),
    ], $overrides));
}

function callShow(EmbeddedOrderAnalyticsController $controller, string $brandId, string $shopifyOrderId): array
{
    $request = Request::create("/internal/embedded/orders/{$shopifyOrderId}", 'GET');
    $request->attributes->set('embedded_professional_id', $brandId);
    $response = $controller->show($request, $shopifyOrderId);

    return json_decode($response->getContent(), true);
}

it('strips the GID prefix from the order id', function () {
    seedOrderRow($this->brandId, $this->affiliateId, ['shopify_order_id' => '12345']);

    $data = callShow($this->controller, $this->brandId, 'gid://shopify/Order/12345');

    expect($data['order_id'])->toBe('12345');
    expect($data['has_affiliate'])->toBeTrue();
});

it('returns has_affiliate:false when no order is found for the brand', function () {
    // Order exists but for a different brand — same tenant-isolation guard.
    seedOrderRow((string) Str::uuid(), $this->affiliateId, ['shopify_order_id' => '12345']);

    $data = callShow($this->controller, $this->brandId, '12345');

    expect($data)->toMatchArray([
        'order_id' => '12345',
        'has_affiliate' => false,
        'affiliate' => null,
        'currency_code' => 'AUD',
        'total_commission_cents' => 0,
        'total_revenue_cents' => 0,
        'line_items' => [],
    ]);
    expect($data['status_summary'])->toBe(['pending' => 0, 'approved' => 0, 'paid' => 0, 'reversed' => 0]);
});

it('returns has_affiliate:false when the order has no affiliate_professional_id', function () {
    // affiliate_professional_id is NOT NULL on the table, so seed an empty string
    // (NOT NULL only enforces presence; falsy check `! $order->affiliate_professional_id` catches '').
    seedOrderRow($this->brandId, '', ['shopify_order_id' => '77777']);

    $data = callShow($this->controller, $this->brandId, '77777');

    expect($data['has_affiliate'])->toBeFalse();
});

it('maps a fully cancelled / voided / refunded order to status=reversed (status branch)', function () {
    $orderId = seedOrderRow($this->brandId, $this->affiliateId, [
        'shopify_order_id' => '111',
        'status' => 'cancelled',
    ]);
    seedOrderItem($orderId, $this->brandId, $this->affiliateId);

    $data = callShow($this->controller, $this->brandId, '111');

    expect($data['line_items'][0]['status'])->toBe('reversed');
    expect($data['status_summary']['reversed'])->toBe(1);
});

it('maps a fully refunded (refund_cents >= net_cents) order to status=reversed (refund branch)', function () {
    $orderId = seedOrderRow($this->brandId, $this->affiliateId, [
        'shopify_order_id' => '222',
        'status' => 'paid',
        'net_cents' => 10000,
        'refund_cents' => 10000,
    ]);
    seedOrderItem($orderId, $this->brandId, $this->affiliateId);

    $data = callShow($this->controller, $this->brandId, '222');

    expect($data['line_items'][0]['status'])->toBe('reversed');
});

it('maps an order with payout_id set to status=paid', function () {
    $orderId = seedOrderRow($this->brandId, $this->affiliateId, [
        'shopify_order_id' => '333',
        'status' => 'paid',
        'payout_id' => (string) Str::uuid(),
    ]);
    seedOrderItem($orderId, $this->brandId, $this->affiliateId);

    $data = callShow($this->controller, $this->brandId, '333');

    expect($data['line_items'][0]['status'])->toBe('paid');
    expect($data['status_summary']['paid'])->toBe(1);
});

it('maps an unpaid, unrefunded, uncancelled order to status=pending and returns full payload shape', function () {
    $orderId = seedOrderRow($this->brandId, $this->affiliateId, [
        'shopify_order_id' => '444',
        'status' => 'paid',
        'payout_id' => null,
        'refund_cents' => 0,
    ]);
    seedOrderItem($orderId, $this->brandId, $this->affiliateId, [
        'shopify_product_id' => '50001',
        'title' => 'Pending Product',
        'quantity' => 2,
        'line_total_cents' => 20000,
        'commission_cents' => 2000,
        'commission_rate' => 0.10,
    ]);

    $data = callShow($this->controller, $this->brandId, '444');

    expect($data['has_affiliate'])->toBeTrue();
    expect($data['affiliate'])->toMatchArray([
        'id' => $this->affiliateId,
        'display_name' => 'Affiliate One',
        'slug' => 'affiliate-one',
    ]);
    expect($data['total_revenue_cents'])->toBe(20000);
    expect($data['total_commission_cents'])->toBe(2000);
    expect($data['line_items'])->toHaveCount(1);
    expect($data['line_items'][0])->toMatchArray([
        'product_id' => '50001',
        'product_title' => 'Pending Product',
        'quantity' => 2,
        'revenue_cents' => 20000,
        'commission_cents' => 2000,
        'commission_rate' => 0.10,
        'status' => 'pending',
    ]);
    expect($data['status_summary']['pending'])->toBe(1);
});
