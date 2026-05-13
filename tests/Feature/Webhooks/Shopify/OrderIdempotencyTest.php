<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Idempotency tests: same X-Shopify-Event-Id delivered twice → second is a no-op
// (DB unique constraint on order_events.shopify_event_id catches it).
// Also verifies the Redis upfront dedup on X-Shopify-Webhook-Id.

beforeEach(function () {
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupProfessionalsTable();
    setupBrandLinkTables();
    setupCommerceOrdersTables();
    setupWebhookEventsTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

it('same X-Shopify-Webhook-Id twice — Redis dedup fires before job dispatch on second delivery', function () {
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

    $payload = ['id' => '111222333', 'updated_at' => now()->toIso8601String(), 'line_items' => [], 'note_attributes' => []];
    $body = json_encode($payload);
    $webhookId = (string) Str::uuid();

    $headers = [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => $webhookId,
        'X-Shopify-Event-Id' => (string) Str::uuid(),
    ];

    $this->postJson('/api/webhooks/shopify/orders-updated', $payload, $headers)->assertOk();
    $this->postJson('/api/webhooks/shopify/orders-updated', $payload, $headers)
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => true]);

    // Only one dispatch regardless of two HTTP deliveries.
    Bus::assertDispatchedTimes(ProcessShopifyOrderUpdatedWebhookJob::class, 1);
});

it('same X-Shopify-Event-Id for order_event — UniqueConstraintViolation is caught as no-op', function () {
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

    // Insert a pre-existing order row (bypassing PG upsert).
    $orderId = (string) Str::uuid();
    DB::table('commerce.orders')->insert([
        'id' => $orderId,
        'shopify_order_id' => 'ORDER-IDEM',
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'brand_professional_id' => $brandId,
        'affiliate_professional_id' => $affiliateId,
        'status' => 'approved',
        'gross_cents' => 5000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 5000,
        'commission_cents' => 500,
        'commission_rate' => 10.0,
        'rate_source' => 'platform_default',
        'currency_code' => 'AUD',
        'line_items' => '[]',
        'shopify_data' => '{}',
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $dupeEventId = 'evt-dupe-123';

    // First delivery: inserts the event row.
    $refundPayload = [
        'id' => '555001',
        'order_id' => 'ORDER-IDEM',
        'created_at' => $now,
        'refund_line_items' => [['id' => '701', 'line_item_id' => '5001', 'quantity' => 1, 'subtotal' => '10.00']],
        'note_attributes' => [['name' => 'affiliate', 'value' => 'affiliate-a']],
    ];

    $job1 = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $refundPayload, 'refunds/create', $dupeEventId);
    app()->call([$job1, 'handle']);

    // Second delivery with SAME event id — should not throw, should be silently ignored.
    $job2 = new ProcessShopifyOrderUpdatedWebhookJob($brandId, $refundPayload, 'refunds/create', $dupeEventId);
    expect(fn () => app()->call([$job2, 'handle']))->not->toThrow(\Throwable::class);

    // Only one event row should exist for this shopify_event_id.
    $eventCount = DB::table('commerce.order_events')
        ->where('shopify_event_id', $dupeEventId)
        ->count();

    expect($eventCount)->toBe(1);
});

it('reconciler-sourced event (null shopify_event_id) — multiple inserts succeed via no unique constraint', function () {
    $now = now()->toDateTimeString();
    $orderId = (string) Str::uuid();

    setupCommerceOrdersTables();

    // Insert a minimal order row for the FK.
    DB::table('commerce.orders')->insert([
        'id' => $orderId,
        'shopify_order_id' => 'ORDER-RECON',
        'shopify_shop_domain' => 'brand-recon.myshopify.com',
        'brand_professional_id' => Str::uuid(),
        'affiliate_professional_id' => Str::uuid(),
        'status' => 'approved',
        'gross_cents' => 5000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 5000,
        'commission_cents' => 500,
        'commission_rate' => 10.0,
        'rate_source' => 'platform_default',
        'currency_code' => 'AUD',
        'line_items' => '[]',
        'shopify_data' => '{}',
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Insert two order_events with NULL shopify_event_id (reconciler source).
    // Both should succeed — partial unique index excludes NULLs.
    DB::table('commerce.order_events')->insert([
        'id' => (string) Str::uuid(),
        'order_id' => $orderId,
        'event_type' => 'paid',
        'source' => 'reconciler',
        'shopify_event_id' => null,
        'shopify_triggered_at' => $now,
        'metadata' => '{}',
    ]);

    // Should not throw on duplicate NULL (NULLs are not equal in SQLite UNIQUE either).
    DB::table('commerce.order_events')->insert([
        'id' => (string) Str::uuid(),
        'order_id' => $orderId,
        'event_type' => 'paid',
        'source' => 'reconciler',
        'shopify_event_id' => null,
        'shopify_triggered_at' => $now,
        'metadata' => '{}',
    ]);

    $count = DB::table('commerce.order_events')->where('order_id', $orderId)->count();
    expect($count)->toBe(2);
});
