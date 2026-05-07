<?php

use App\Jobs\Fresha\SyncFreshaCatalogDeltaJob;
use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();

    Config::set('services.shopify.webhook_secret', 'shop-secret');
    Config::set('services.fresha.webhook_signature_key', 'fresha-key');
    Config::set('services.fresha.webhook_notification_url', 'http://localhost/api/webhooks/fresha');
    Config::set('services.square.webhook_signature_key', 'square-key');
    Config::set('services.square.webhook_notification_url', 'http://localhost/api/webhooks/square');
    Config::set('partna.features.fresha_sync', true);
    Config::set('partna.features.square_sync', true);
});

it('shopify orders/paid — empty body with valid HMAC for empty body returns 200 and dispatches nothing', function () {
    $body = '';
    $sig = signShopifyBody($body, 'shop-secret');

    $this->call('POST', '/api/webhooks/shopify/orders-paid', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $sig,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'brand-a.myshopify.com',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-empty-1',
    ], $body)->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('shopify orders/paid — malformed JSON with valid HMAC returns 200 and dispatches nothing', function () {
    $body = '{not valid json';
    $sig = signShopifyBody($body, 'shop-secret');

    $this->call('POST', '/api/webhooks/shopify/orders-paid', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $sig,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'brand-a.myshopify.com',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-malformed-1',
    ], $body)->assertOk();

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('fresha — JSON array (not object) body is gracefully ignored', function () {
    $body = '[]';
    $sig = signFreshaBody('http://localhost/api/webhooks/fresha', $body, 'fresha-key');

    $this->call('POST', '/api/webhooks/fresha', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FRESHA_SIGNATURE' => $sig,
    ], $body)->assertOk();

    Bus::assertNotDispatched(SyncFreshaCatalogDeltaJob::class);
});

it('square — payload that is not an array is gracefully ignored', function () {
    $body = '"plain string"';  // valid JSON, but not an object
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'square-key');

    $this->call('POST', '/api/webhooks/square', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $sig,
    ], $body)->assertOk();

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});
