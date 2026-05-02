<?php

use App\Http\Controllers\Api\Webhooks\ShopifyOrderWebhookController;
use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        provider TEXT,
        access_token TEXT,
        provider_metadata TEXT,
        status TEXT,
        expires_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

/**
 * Build a Shopify order webhook request with a valid HMAC-SHA256 signature.
 */
function buildShopifyWebhookRequest(array $payload, string $shopDomain, string $secret): Request
{
    $body = json_encode($payload);
    $hmac = base64_encode(hash_hmac('sha256', $body, $secret, true));

    return Request::create('/api/webhooks/shopify/orders', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => (string) Str::uuid(),
    ], $body);
}

it('shopify order webhook rejects a payload for an unknown shop domain', function () {
    $secret = 'test-webhook-secret-123';
    Config::set('services.shopify.webhook_secret', $secret);

    $body = ['id' => 999, 'total_price' => '12.00'];
    $req = buildShopifyWebhookRequest($body, 'unknown-shop.myshopify.com', $secret);

    $response = app(ShopifyOrderWebhookController::class)->__invoke($req);

    // Returns 200 (always acknowledges), but no order record is created.
    expect($response->getStatusCode())->toBe(200);
    expect($response->getData(true)['received'] ?? false)->toBeTrue();
});

it('shopify order webhook for brand A domain does not process events for brand B domain', function () {
    Queue::fake();

    [$a, $b] = createTwoTenants('brand');
    $secret = 'test-webhook-secret-456';
    Config::set('services.shopify.webhook_secret', $secret);
    $now = now()->toDateTimeString();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $a->id,
        'provider' => 'shopify',
        'access_token' => 'token-a',
        'provider_metadata' => json_encode(['shop_domain' => 'brand-a.myshopify.com']),
        'status' => 'connected',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Payload claims to come from brand B's shop — but B has no integration.
    // The webhook should acknowledge but dispatch nothing for either brand.
    $payload = ['id' => 12345, 'total_price' => '50.00'];
    $req = buildShopifyWebhookRequest($payload, 'brand-b.myshopify.com', $secret);

    $response = app(ShopifyOrderWebhookController::class)->__invoke($req);

    expect($response->getStatusCode())->toBe(200);

    // No processing job dispatched — Brand B's unregistered domain must not trigger
    // Brand A's integration as a fallback.
    Queue::assertNotPushed(ProcessShopifyOrderWebhookJob::class);

    // Brand A's integration record is unmodified (not used by Brand B's webhook).
    $aRecord = DB::table('core.professional_integrations')
        ->where('professional_id', $a->id)
        ->first();
    expect($aRecord->access_token)->toBe('token-a');
});

it('shopify order webhook with invalid hmac returns 401 without processing', function () {
    $secret = 'test-webhook-secret-789';
    Config::set('services.shopify.webhook_secret', $secret);

    $body = json_encode(['id' => 777, 'total_price' => '100.00']);
    $req = Request::create('/api/webhooks/shopify/orders', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'attacker.myshopify.com',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode('invalid-signature'),
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => (string) Str::uuid(),
    ], $body);

    $response = app(ShopifyOrderWebhookController::class)->__invoke($req);

    expect($response->getStatusCode())->toBe(401);
});
