<?php

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
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
    Config::set('services.shopify.fallback_secret', '');
});

function realShopifyOrderPayload(): array
{
    return [
        'id' => 5732445487345,
        'admin_graphql_api_id' => 'gid://shopify/Order/5732445487345',
        'name' => '#1023',
        'email' => 'shopper@example.com',
        'financial_status' => 'paid',
        'total_price' => '49.99',
        'currency' => 'USD',
        'customer' => [
            'id' => 7891234567,
            'email' => 'shopper@example.com',
            'first_name' => 'Test',
            'last_name' => 'Shopper',
        ],
        'line_items' => [
            [
                'id' => 13456789,
                'product_id' => 8765432,
                'variant_id' => 99887766,
                'quantity' => 1,
                'price' => '49.99',
            ],
        ],
        'created_at' => '2026-04-27T12:00:00-04:00',
    ];
}

function insertShopifyIntegration(string $proId, string $shopDomain): void
{
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => $shopDomain,
        'access_token' => 'shpat_token',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('orders/paid — silently acknowledges 200 with bad HMAC and dispatches nothing', function () {
    $payload = realShopifyOrderPayload();

    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, [
        'X-Shopify-Hmac-SHA256' => 'invalid-hmac',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('orders/paid — accepts valid HMAC and dispatches job with real-shape payload', function () {
    $proId = (string) Str::uuid();
    insertShopifyIntegration($proId, 'brand-a.myshopify.com');

    $payload = realShopifyOrderPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertDispatched(
        ProcessShopifyOrderWebhookJob::class,
        function (ProcessShopifyOrderWebhookJob $job) use ($proId, $payload) {
            return $job->brandProfessionalId === $proId
                && $job->orderPayload['id'] === $payload['id']
                && $job->orderPayload['line_items'][0]['product_id'] === 8765432;
        }
    );
});

it('orders/paid — second delivery with same X-Shopify-Webhook-Id returns duplicate=true', function () {
    $proId = (string) Str::uuid();
    insertShopifyIntegration($proId, 'brand-a.myshopify.com');

    $payload = realShopifyOrderPayload();
    $body = json_encode($payload);
    $webhookId = (string) Str::uuid();

    $headers = [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => $webhookId,
    ];

    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, $headers)->assertOk();
    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, $headers)
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => true]);

    Bus::assertDispatchedTimes(ProcessShopifyOrderWebhookJob::class, 1);
});

it('orders/paid — accepts a body signed with the fallback secret during rotation', function () {
    $proId = (string) Str::uuid();
    insertShopifyIntegration($proId, 'brand-a.myshopify.com');

    Config::set('services.shopify.webhook_secret', 'new-rotated-secret');
    Config::set('services.shopify.fallback_secret', 'old-secret-still-deployed-by-shopify');

    $payload = realShopifyOrderPayload();
    $body = json_encode($payload);

    // Shopify is still signing with the OLD secret — must succeed.
    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'old-secret-still-deployed-by-shopify'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('orders/paid — rejects when neither primary nor fallback secret matches', function () {
    Config::set('services.shopify.webhook_secret', 'real-primary');
    Config::set('services.shopify.fallback_secret', 'real-fallback');

    $payload = realShopifyOrderPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/orders-paid', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'attacker-guessed-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk(); // Always 200 (no retry signal), but no dispatch.

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});
