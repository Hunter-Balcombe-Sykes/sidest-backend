<?php

use App\Exceptions\Shopify\ShopifyTransportException;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // REST does not use Redis — no bucket cleanup needed.
    $this->client = app(ShopifyAdminClient::class);
    $this->shop = 'test.myshopify.com';
    $this->token = 'shpat_test';
    $this->version = '2025-01';
});

it('performs a successful DELETE', function () {
    Http::fake([
        "https://{$this->shop}/admin/api_permissions/current.json" => Http::response('', 204),
    ]);

    $response = $this->client->rest('DELETE', $this->shop, $this->token, '/admin/api_permissions/current.json');

    expect($response->status())->toBe(204);
});

it('retries once after a 429 with Retry-After and succeeds', function () {
    Http::fakeSequence("https://{$this->shop}/admin/api_permissions/current.json")
        ->push('', 429, ['Retry-After' => '1'])
        ->push('', 204);

    $response = $this->client->rest('DELETE', $this->shop, $this->token, '/admin/api_permissions/current.json');

    expect($response->status())->toBe(204);
    Http::assertSentCount(2);
});

it('treats 401 as successful for token-revoke semantics', function () {
    Http::fake([
        "https://{$this->shop}/admin/api_permissions/current.json" => Http::response('', 401),
    ]);

    $response = $this->client->rest('DELETE', $this->shop, $this->token, '/admin/api_permissions/current.json', allow401: true);

    expect($response->status())->toBe(401);
});

it('throws ShopifyTransportException on non-2xx after 429 retries exhausted', function () {
    config()->set('services.shopify.throttle.max_inprocess_retries', 1);

    Http::fake([
        "https://{$this->shop}/admin/api_permissions/current.json" => Http::response('', 429, ['Retry-After' => '1']),
    ]);

    expect(fn () => $this->client->rest('DELETE', $this->shop, $this->token, '/admin/api_permissions/current.json'))
        ->toThrow(ShopifyTransportException::class);
});
