<?php

use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\Client\ShopifyBulkOperationLock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::del('shopify:bulk_lock:test.myshopify.com');
    Redis::del('shopify:bucket:test.myshopify.com');
    $this->client = app(ShopifyAdminClient::class);
    $this->shop = 'test.myshopify.com';
    $this->token = 'shpat_test';
    $this->version = '2025-01';
});

it('starts a bulk query and returns the operation id', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => [
                'bulkOperationRunQuery' => [
                    'bulkOperation' => ['id' => 'gid://shopify/BulkOperation/1', 'status' => 'CREATED'],
                    'userErrors' => [],
                ],
            ],
            'extensions' => ['cost' => ['requestedQueryCost' => 10, 'actualQueryCost' => 10, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 990, 'restoreRate' => 100]]],
        ], 200),
    ]);

    $id = $this->client->bulkQuery($this->shop, $this->token, $this->version, 'query { products { edges { node { id } } } }');

    expect($id)->toBe('gid://shopify/BulkOperation/1');
});

it('refuses to start a bulk op when another is in flight on the same shop', function () {
    app(ShopifyBulkOperationLock::class)->acquire($this->shop);

    expect(fn () => $this->client->bulkQuery($this->shop, $this->token, $this->version, 'query { products { edges { node { id } } } }'))
        ->toThrow(\RuntimeException::class, 'already in progress');
});

it('polls waitForBulkOperation until COMPLETED and returns the url', function () {
    Http::fakeSequence("https://{$this->shop}/admin/api/{$this->version}/graphql.json")
        ->push([
            'data' => ['node' => ['status' => 'RUNNING', 'url' => null]],
            'extensions' => ['cost' => ['requestedQueryCost' => 1, 'actualQueryCost' => 1, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 999, 'restoreRate' => 100]]],
        ], 200)
        ->push([
            'data' => ['node' => ['status' => 'COMPLETED', 'url' => 'https://example.com/bulk.jsonl']],
            'extensions' => ['cost' => ['requestedQueryCost' => 1, 'actualQueryCost' => 1, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 999, 'restoreRate' => 100]]],
        ], 200);

    $result = $this->client->waitForBulkOperation($this->shop, $this->token, $this->version, 'gid://shopify/BulkOperation/1', pollIntervalMs: 10, timeoutSeconds: 5);

    expect($result['status'])->toBe('COMPLETED')
        ->and($result['url'])->toBe('https://example.com/bulk.jsonl');
});
