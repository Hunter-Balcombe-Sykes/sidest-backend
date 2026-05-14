<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Decision #3: commission_cents and commission_rate are FROZEN at orders/paid time.
// An orders/edited event updates gross_cents, line_items, shopify_data — but must
// NEVER change commission_cents or commission_rate.

beforeEach(function () {
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupProfessionalsTable();
    setupBrandLinkTables();
    setupCommerceOrdersTables();
    setupWebhookEventsTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

it('orders/edited — commission_cents and commission_rate unchanged after snapshot update', function () {
    $now = now()->toDateTimeString();
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();

    DB::table('core.professionals')->insert([
        ['id' => $brandId, 'handle' => 'brand-a', 'handle_lc' => 'brand-a', 'display_name' => 'Brand A', 'created_at' => $now, 'updated_at' => $now],
        ['id' => $affiliateId, 'handle' => 'affiliate-a', 'handle_lc' => 'affiliate-a', 'display_name' => 'Affiliate A', 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::table('core.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'provider' => \App\Models\Core\Professional\ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Insert a "paid" order with known commission values (frozen at orders/paid time).
    $orderId = (string) Str::uuid();
    $frozenCommissionCents = 500;
    $frozenCommissionRate = 5.0;

    DB::table('commerce.orders')->insert([
        'id' => $orderId,
        'shopify_order_id' => 'ORDER-EDIT-TEST',
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => 10000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 10000,
        'commission_cents' => $frozenCommissionCents,
        'commission_rate' => $frozenCommissionRate,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'line_items' => '[]',
        'shopify_data' => '{}',
        'shopify_updated_at' => '2026-05-01T10:00:00+00:00',
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // orders/edited arrives with different gross and line_items (brand edited the order).
    $editedPayload = [
        'id' => 'ORDER-EDIT-TEST',
        'domain' => 'brand-a.myshopify.com',
        'financial_status' => 'paid',
        'total_price' => '150.00',   // changed gross
        'updated_at' => '2026-05-01T11:00:00+00:00',  // later than the stored row
        'note_attributes' => [['name' => 'affiliate', 'value' => 'affiliate-a']],
        'line_items' => [
            [
                'id' => '5001',
                'product_id' => '8001',
                'variant_id' => '9001',
                'title' => 'Product A (Edited)',
                'price' => '150.00',
                'quantity' => 1,
                'total_discount' => '0.00',
            ],
        ],
    ];

    $job = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $editedPayload, 'orders/edited', 'evt-edit-snapshot');
    app()->call([$job, 'handle']);

    $order = DB::table('commerce.orders')
        ->where('shopify_order_id', 'ORDER-EDIT-TEST')
        ->first();

    expect($order)->not->toBeNull();

    // Commission MUST be frozen — no change regardless of edited gross.
    expect((int) $order->commission_cents)->toBe($frozenCommissionCents);
    expect((float) $order->commission_rate)->toBe($frozenCommissionRate);

    // An order_events row for 'edited' should exist.
    $event = DB::table('commerce.order_events')
        ->where('order_id', $orderId)
        ->where('shopify_event_id', 'evt-edit-snapshot')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->event_type)->toBe('edited');
});

it('orders/edited controller — dispatches job with topic orders/edited and event id', function () {
    \Illuminate\Support\Facades\Bus::fake();

    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => \App\Models\Core\Professional\ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = ['id' => 'ORDER-EDIT-CTRL', 'updated_at' => now()->toIso8601String(), 'line_items' => []];
    $body = json_encode($payload);
    $eventId = (string) Str::uuid();

    $this->postJson('/api/webhooks/shopify/orders-edited', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
        'X-Shopify-Event-Id' => $eventId,
    ])->assertOk();

    \Illuminate\Support\Facades\Bus::assertDispatched(ProcessShopifyOrderUpdatedWebhookJob::class, function ($job) use ($eventId) {
        return $job->topic === 'orders/edited'
            && $job->shopifyEventId === $eventId;
    });
});
