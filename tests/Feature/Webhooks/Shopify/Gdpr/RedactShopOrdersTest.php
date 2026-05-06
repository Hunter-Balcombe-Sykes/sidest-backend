<?php

use App\Jobs\Shopify\Gdpr\RedactShopJob;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Postgres-only: exercises the jsonb_strip_pii path in RedactShopJob.
// The function does not exist in SQLite so the test must be skipped on non-pgsql connections.
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires Postgres jsonb_strip_pii function.');
    }
});

/**
 * Seed a brand with an integration + multiple orders (some with PII).
 * Also seeds a second brand's orders to verify isolation — only the target
 * brand's data should be redacted.
 *
 * @return array{
 *   professionalId: string,
 *   shopDomain: string,
 *   orderIdA: string,
 *   orderIdB: string,
 *   eventIdA: string,
 *   otherBrandOrderId: string,
 *   gdprId: string
 * }
 */
function seedRedactShopOrdersFixture(): array
{
    $professionalId = (string) Str::uuid();
    $shopDomain = 'shop-brand-'.Str::random(6).'.myshopify.com';
    $affiliateId = (string) Str::uuid();
    $now = now()->toIso8601String();

    // Target brand integration
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'access_token' => 'shpat_live',
        'refresh_token' => 'shpref_live',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $customerId = (string) Str::uuid();
    DB::table('core.customers')->insert([
        'id' => $customerId,
        'professional_id' => $professionalId,
        'email' => 'shopper@example.com',
        'full_name' => 'Shop Shopper',
        'source' => 'shopify',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Affiliate product selection — should be deleted by the job.
    AffiliateProductSelection::create([
        'affiliate_professional_id' => $affiliateId,
        'brand_professional_id' => $professionalId,
        'shopify_product_gid' => 'gid://shopify/Product/1',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // PII-bearing shopify_data for the first order.
    $shopifyDataA = [
        'customer' => [
            'email' => 'shopper@example.com',
            'first_name' => 'Shop',
            'last_name' => 'Shopper',
            'phone' => '+9876',
        ],
        'billing_address' => ['address1' => '1 Brand St', 'city' => 'Brisbane', 'name' => 'Shop Shopper'],
        'shipping_address' => ['address1' => '2 Brand St', 'city' => 'Brisbane'],
        'note' => 'Gift wrap please',
        'note_attributes' => [
            ['name' => 'gift_message', 'value' => 'Happy Birthday from Shopper'],
        ],
        'line_items' => [
            ['id' => 10, 'properties' => [['name' => 'custom_text', 'value' => 'For Shopper']]],
        ],
        'currency' => 'AUD',
    ];

    $orderIdA = (string) Str::uuid();
    DB::table('commerce.orders')->insert([
        'id' => $orderIdA,
        'shopify_order_id' => 'gid://shopify/Order/1001',
        'shopify_shop_domain' => $shopDomain,
        'brand_professional_id' => $professionalId,
        'affiliate_professional_id' => $affiliateId,
        'customer_id' => $customerId,
        'status' => 'paid',
        'gross_cents' => 10000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 10000,
        'commission_cents' => 1000,
        'commission_rate' => 0.10,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'line_items' => json_encode($shopifyDataA['line_items']),
        'shopify_data' => json_encode($shopifyDataA),
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // A second order for the same brand (no customer link — anonymous checkout).
    $shopifyDataB = [
        'customer' => ['email' => 'anon@example.com', 'first_name' => 'Anon', 'last_name' => 'Buyer', 'phone' => null],
        'note' => 'Leave at door',
        'currency' => 'AUD',
    ];

    $orderIdB = (string) Str::uuid();
    DB::table('commerce.orders')->insert([
        'id' => $orderIdB,
        'shopify_order_id' => 'gid://shopify/Order/1002',
        'shopify_shop_domain' => $shopDomain,
        'brand_professional_id' => $professionalId,
        'affiliate_professional_id' => $affiliateId,
        'customer_id' => null,
        'status' => 'paid',
        'gross_cents' => 3000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 3000,
        'commission_cents' => 300,
        'commission_rate' => 0.10,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'line_items' => '[]',
        'shopify_data' => json_encode($shopifyDataB),
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // An order_event referencing orderA.
    $metadataA = [
        'refund' => ['id' => 'r-shop-1', 'note' => 'Shopper wants refund'],
        'customer' => ['email' => 'shopper@example.com', 'name' => 'Shop Shopper', 'phone' => '+9876'],
        'amount_cents' => 1000,
    ];

    $eventIdA = (string) Str::uuid();
    DB::table('commerce.order_events')->insert([
        'id' => $eventIdA,
        'order_id' => $orderIdA,
        'event_type' => 'refund_created',
        'amount_delta_cents' => -1000,
        'metadata' => json_encode($metadataA),
        'source' => 'webhook',
        'shopify_triggered_at' => $now,
    ]);

    // A DIFFERENT brand — its order must not be touched by this redact job.
    $otherBrandId = (string) Str::uuid();
    $otherShopDomain = 'other-shop-'.Str::random(6).'.myshopify.com';

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $otherBrandId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'provider_metadata' => json_encode(['shop_domain' => $otherShopDomain]),
        'shopify_shop_domain' => $otherShopDomain,
        'access_token' => 'shpat_other',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $otherShopifyData = [
        'customer' => ['email' => 'safe@example.com', 'first_name' => 'Safe', 'last_name' => 'Person', 'phone' => '+0000'],
        'note' => 'This note should not be redacted',
        'currency' => 'AUD',
    ];

    $otherBrandOrderId = (string) Str::uuid();
    DB::table('commerce.orders')->insert([
        'id' => $otherBrandOrderId,
        'shopify_order_id' => 'gid://shopify/Order/9999',
        'shopify_shop_domain' => $otherShopDomain,
        'brand_professional_id' => $otherBrandId,
        'affiliate_professional_id' => $affiliateId,
        'customer_id' => null,
        'status' => 'paid',
        'gross_cents' => 5000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 5000,
        'commission_cents' => 500,
        'commission_rate' => 0.10,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'line_items' => '[]',
        'shopify_data' => json_encode($otherShopifyData),
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $gdpr = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_SHOP_REDACT,
        'shop_domain' => $shopDomain,
        'payload_hash' => hash('sha256', 'shop-'.$professionalId),
        'payload' => ['shop_domain' => $shopDomain],
        'professional_id' => $professionalId,
        'received_at' => now(),
    ]);

    return [
        'professionalId' => $professionalId,
        'shopDomain' => $shopDomain,
        'orderIdA' => $orderIdA,
        'orderIdB' => $orderIdB,
        'eventIdA' => $eventIdA,
        'otherBrandOrderId' => $otherBrandOrderId,
        'gdprId' => $gdpr->id,
    ];
}

it('NULLs customer_id on all orders for the brand', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    $orderA = DB::table('commerce.orders')->where('id', $ctx['orderIdA'])->first();
    $orderB = DB::table('commerce.orders')->where('id', $ctx['orderIdB'])->first();

    expect($orderA->customer_id)->toBeNull();
    expect($orderB->customer_id)->toBeNull();
});

it('redacts customer PII fields in shopify_data for all brand orders', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    $orderA = DB::table('commerce.orders')->where('id', $ctx['orderIdA'])->first();
    $dataA = json_decode($orderA->shopify_data, true);

    expect($dataA['customer']['email'])->toBe('REDACTED');
    expect($dataA['customer']['first_name'])->toBe('REDACTED');
    expect($dataA['customer']['last_name'])->toBe('REDACTED');

    // Second order — also redacted (scoped to brand, not customer)
    $orderB = DB::table('commerce.orders')->where('id', $ctx['orderIdB'])->first();
    $dataB = json_decode($orderB->shopify_data, true);
    expect($dataB['customer']['email'])->toBe('REDACTED');
});

it('redacts billing_address and shipping_address for brand orders', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderIdA'])->first();
    $data = json_decode($order->shopify_data, true);

    expect($data['billing_address'])->toBe('REDACTED');
    expect($data['shipping_address'])->toBe('REDACTED');
});

it('redacts note and note_attributes[*].value for brand orders', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderIdA'])->first();
    $data = json_decode($order->shopify_data, true);

    expect($data['note'])->toBe('REDACTED');
    expect($data['note_attributes'][0]['value'])->toBe('REDACTED');
    expect($data['note_attributes'][0]['name'])->toBe('gift_message'); // label preserved
});

it('redacts line_items[*].properties[*].value but preserves line item ids', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderIdA'])->first();
    $data = json_decode($order->shopify_data, true);

    expect($data['line_items'][0]['properties'][0]['value'])->toBe('REDACTED');
    expect($data['line_items'][0]['id'])->toBe(10);
});

it('preserves non-PII fields in shopify_data', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderIdA'])->first();
    $data = json_decode($order->shopify_data, true);

    expect($data['currency'])->toBe('AUD');
});

it('redacts PII in order_events metadata for brand orders', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    $event = DB::table('commerce.order_events')->where('id', $ctx['eventIdA'])->first();
    $meta = json_decode($event->metadata, true);

    expect($meta['refund']['note'])->toBe('REDACTED');
    expect($meta['customer']['email'])->toBe('REDACTED');
    expect($meta['customer']['name'])->toBe('REDACTED');
    expect($meta['customer']['phone'])->toBe('REDACTED');
});

it('preserves non-PII fields in order_events metadata', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    $event = DB::table('commerce.order_events')->where('id', $ctx['eventIdA'])->first();
    $meta = json_decode($event->metadata, true);

    expect($meta['refund']['id'])->toBe('r-shop-1');
    expect($meta['amount_cents'])->toBe(1000);
});

it('does not redact orders belonging to a different brand (isolation check)', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    $otherOrder = DB::table('commerce.orders')->where('id', $ctx['otherBrandOrderId'])->first();
    $data = json_decode($otherOrder->shopify_data, true);

    // Other brand's order should be completely untouched.
    expect($data['customer']['email'])->toBe('safe@example.com');
    expect($data['customer']['first_name'])->toBe('Safe');
    expect($data['note'])->toBe('This note should not be redacted');
});

it('marks the gdpr request completed', function () {
    $ctx = seedRedactShopOrdersFixture();

    (new RedactShopJob($ctx['gdprId']))->handle();

    expect(GdprRequest::find($ctx['gdprId'])->status)->toBe(GdprRequest::STATUS_COMPLETED);
});
