<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Race 1: orders/updated arrives FIRST (older shopify_updated_at), then orders/paid arrives
// SECOND (newer shopify_updated_at). Final state should match the paid snapshot.
// The LWW WHERE guard in SQL ensures the older update does NOT overwrite the newer paid row.
// Controller dispatch tests run on SQLite; DB LWW guard tests are pgsql-only.

beforeEach(function () {
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupCommerceOrdersTables();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
    Config::set('services.shopify.fallback_secret', '');
});

it('orders/updated controller — dispatches job with topic orders/updated', function () {
    Bus::fake();

    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = [
        'id' => '111222333',
        'financial_status' => 'paid',
        'updated_at' => '2026-05-01T09:00:00+00:00',  // older timestamp
        'line_items' => [],
        'note_attributes' => [['name' => 'affiliate', 'value' => 'affiliate-a']],
    ];
    $body = json_encode($payload);
    $eventId = (string) Str::uuid();

    $this->postJson('/api/webhooks/shopify/orders-updated', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
        'X-Shopify-Event-Id' => $eventId,
    ])->assertOk();

    Bus::assertDispatched(ProcessShopifyOrderUpdatedWebhookJob::class, function ($job) use ($eventId) {
        return $job->topic === 'orders/updated'
            && $job->shopifyEventId === $eventId;
    });
});

it('LWW guard — older orders/updated does not overwrite newer paid row', function () {
    $conn = DB::connection('pgsql');
    if ($conn->getDriverName() === 'sqlite') {
        $this->markTestSkipped('LWW WHERE guard requires pgsql; SQLite cannot enforce timestamp comparison in UPDATE WHERE');
    }

    setupProfessionalsTable();
    setupBrandLinkTables();

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
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Insert the authoritative "paid" row with gross_cents=10000 and a LATER shopify_updated_at.
    $orderId = (string) Str::uuid();
    DB::table('commerce.orders')->insert([
        'id' => $orderId,
        'shopify_order_id' => '111222333',
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => 10000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 10000,
        'commission_cents' => 1000,
        'commission_rate' => 10.0,
        'rate_source' => 'platform_default',
        'currency_code' => 'AUD',
        'line_items' => '[]',
        'shopify_data' => '{}',
        'shopify_updated_at' => '2026-05-01T10:00:00+00:00',  // later = authoritative paid timestamp
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Send orders/updated with an OLDER timestamp and a DIFFERENT gross_cents (99999).
    // The LWW WHERE guard (shopify_updated_at < incoming) must reject this stale snapshot.
    $updatedPayload = [
        'id' => '111222333',
        'financial_status' => 'paid',
        'updated_at' => '2026-04-30T00:00:00+00:00',  // OLDER than the stored paid row
        'line_items' => [['id' => '99', 'price' => '999.99', 'quantity' => 1, 'total_discount' => '0']],
        'note_attributes' => [['name' => 'affiliate', 'value' => 'affiliate-a']],
    ];

    $job = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $updatedPayload, 'orders/updated', 'evt-stale');
    app()->call([$job, 'handle']);

    // gross_cents IS in the snapshotUpdate() SET clause.
    // If the LWW guard fires correctly, it stays at 10000 (not overwritten with 99999).
    $order = DB::table('commerce.orders')->where('shopify_order_id', '111222333')->first();
    expect($order)->not->toBeNull();
    expect((int) $order->gross_cents)->toBe(10000);  // LWW guard must reject the stale 99999 value
});
