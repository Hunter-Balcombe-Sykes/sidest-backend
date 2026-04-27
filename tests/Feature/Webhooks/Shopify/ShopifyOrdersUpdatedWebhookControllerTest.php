<?php

use App\Jobs\Shopify\ProcessShopifyOrderUpdatedWebhookJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

function realShopifyOrderUpdatedPayload(): array
{
    return [
        'id' => 5732445487345,
        'admin_graphql_api_id' => 'gid://shopify/Order/5732445487345',
        'name' => '#1023',
        'financial_status' => 'refunded',
        'total_price' => '49.99',
        'currency' => 'USD',
        'refunds' => [
            [
                'id' => 998877,
                'created_at' => '2026-04-27T13:00:00-04:00',
                'refund_line_items' => [
                    ['id' => 11, 'quantity' => 1, 'subtotal' => '49.99'],
                ],
            ],
        ],
        'updated_at' => '2026-04-27T13:00:00-04:00',
    ];
}

it('orders/updated — silently acknowledges 200 with bad HMAC, dispatches nothing', function () {
    $this->postJson('/api/webhooks/shopify/orders-updated', realShopifyOrderUpdatedPayload(), [
        'X-Shopify-Hmac-SHA256' => 'bogus',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderUpdatedWebhookJob::class);
});

it('orders/updated — accepts valid HMAC and dispatches with real-shape refund payload', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_token',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = realShopifyOrderUpdatedPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/orders-updated', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertDispatched(ProcessShopifyOrderUpdatedWebhookJob::class);
});

it('orders/updated — unknown shop_domain logs warning and skips dispatch', function () {
    $payload = realShopifyOrderUpdatedPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/orders-updated', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'ghost.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderUpdatedWebhookJob::class);
});
