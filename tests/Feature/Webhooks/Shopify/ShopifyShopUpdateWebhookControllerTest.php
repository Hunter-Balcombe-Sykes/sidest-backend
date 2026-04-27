<?php

use App\Jobs\Shopify\ProcessShopifyShopUpdateJob;
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

function realShopifyShopUpdatePayload(): array
{
    return [
        'id' => 12345678,
        'name' => 'Brand A Cosmetics',
        'email' => 'owner@brand-a.example',
        'domain' => 'brand-a.myshopify.com',
        'myshopify_domain' => 'brand-a.myshopify.com',
        'shop_owner' => 'Test Owner',
        'currency' => 'USD',
        'iana_timezone' => 'America/New_York',
        'updated_at' => '2026-04-27T14:00:00-04:00',
    ];
}

it('shop/update — bad HMAC silently 200s, no dispatch', function () {
    $this->postJson('/api/webhooks/shopify/shop-update', realShopifyShopUpdatePayload(), [
        'X-Shopify-Hmac-SHA256' => 'bad',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyShopUpdateJob::class);
});

it('shop/update — valid HMAC dispatches ProcessShopifyShopUpdateJob with payload', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = realShopifyShopUpdatePayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/shop-update', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertDispatched(ProcessShopifyShopUpdateJob::class);
});

it('shop/update — duplicate webhook_id returns duplicate=true and skips dispatch', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = realShopifyShopUpdatePayload();
    $body = json_encode($payload);
    $headers = [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => 'webhook-shop-update-1',
    ];

    $this->postJson('/api/webhooks/shopify/shop-update', $payload, $headers)->assertOk();
    $this->postJson('/api/webhooks/shopify/shop-update', $payload, $headers)
        ->assertOk()
        ->assertJson(['received' => true, 'duplicate' => true]);

    Bus::assertDispatchedTimes(ProcessShopifyShopUpdateJob::class, 1);
});

it('shop/update — unknown shop_domain 200s without dispatch', function () {
    $payload = realShopifyShopUpdatePayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/shop-update', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'ghost.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ])->assertOk();

    Bus::assertNotDispatched(ProcessShopifyShopUpdateJob::class);
});
