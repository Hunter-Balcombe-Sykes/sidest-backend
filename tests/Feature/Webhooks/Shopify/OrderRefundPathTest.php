<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Verifies the refund path: controller dispatches job with topic='refunds/create' + event id.
// Full DB assertions (status='partially_refunded', refund_cents updated, rollup clawback)
// require pgsql triggers — those are marked skipped on SQLite.

beforeEach(function () {
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupProfessionalsTable();
    setupBrandLinkTables();
    setupCommerceOrdersTables();
    setupWebhookEventsTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
    Config::set('services.shopify.fallback_secret', '');
});

function refundPayload(string $orderId = '111222333', string $refundId = '555666'): array
{
    return [
        'id' => $refundId,
        'order_id' => $orderId,
        'created_at' => '2026-05-01T11:00:00+00:00',
        'note' => null,
        'refund_line_items' => [
            [
                'id' => '701',
                'line_item_id' => '5001',
                'quantity' => 1,
                'subtotal' => '25.00',
                'total_tax' => '0.00',
            ],
        ],
        'note_attributes' => [
            ['name' => 'affiliate', 'value' => 'affiliate-a'],
        ],
    ];
}

it('refunds/create controller — dispatches job with topic refunds/create and event id', function () {
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

    $payload = refundPayload();
    $body = json_encode($payload);
    $eventId = (string) Str::uuid();

    $this->postJson('/api/webhooks/shopify/refunds-create', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
        'X-Shopify-Event-Id' => $eventId,
    ])->assertOk()->assertJson(['received' => true]);

    Bus::assertDispatched(ProcessShopifyOrderUpdatedWebhookJob::class, function ($job) use ($eventId) {
        return $job->topic === 'refunds/create'
            && $job->shopifyEventId === $eventId;
    });
});

it('refunds/create after orders/paid — updates refund_cents and status', function () {
    $conn = DB::connection('pgsql');
    if ($conn->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Refund UPDATE requires pgsql for LWW guard');
    }

    // SQLite path: insert a pre-existing order directly (bypassing PG-only upsert),
    // then run the refund job and assert refund_cents + status are updated.
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

    // Insert a pre-existing "paid" order directly (PG upsert not available in SQLite).
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
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
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

    $job = new ProcessShopifyOrderUpdatedWebhookJob(
        $brandId,
        refundPayload('111222333'),
        'refunds/create',
        'evt-refund-1',
    );

    app()->call([$job, 'handle']);

    $order = DB::table('commerce.orders')
        ->where('shopify_order_id', '111222333')
        ->first();

    // After a $25 refund on a $100 order: partially_refunded, refund_cents = 2500.
    expect((int) $order->refund_cents)->toBe(2500);
    expect($order->status)->toBe('partially_refunded');

    // An order_event row with event_type='partially_refunded' should be inserted.
    $event = DB::table('commerce.order_events')
        ->where('order_id', $orderId)
        ->where('shopify_event_id', 'evt-refund-1')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->event_type)->toBe('partially_refunded');
});
