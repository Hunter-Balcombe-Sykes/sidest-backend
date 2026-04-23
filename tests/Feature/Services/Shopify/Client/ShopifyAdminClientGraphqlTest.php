<?php

use App\Exceptions\Shopify\ShopifyGraphQLException;
use App\Exceptions\Shopify\ShopifyTransportException;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::del('shopify:bucket:test.myshopify.com');
    Redis::del('shopify:cost:' . sha1('query { shop { id } }'));
    Redis::del('shopify:cost:' . sha1('query { foo }'));
    Redis::del('shopify:cost:' . sha1('query { ok }'));
    $this->client = app(ShopifyAdminClient::class);
    $this->shop = 'test.myshopify.com';
    $this->token = 'shpat_test';
    $this->version = '2025-01';
});

it('returns the Response object on a successful GraphQL call', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['shop' => ['id' => 'gid://shopify/Shop/1']],
            'extensions' => [
                'cost' => [
                    'requestedQueryCost' => 1,
                    'actualQueryCost' => 1,
                    'throttleStatus' => [
                        'maximumAvailable' => 1000,
                        'currentlyAvailable' => 999,
                        'restoreRate' => 100,
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }');

    expect($response->json('data.shop.id'))->toBe('gid://shopify/Shop/1');
});

it('throws ShopifyGraphQLException when top-level errors are present (non-throttled)', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'errors' => [
                ['message' => 'Field "foo" does not exist', 'extensions' => ['code' => 'undefinedField']],
            ],
        ], 200),
    ]);

    expect(fn () => $this->client->graphql($this->shop, $this->token, $this->version, 'query { foo }'))
        ->toThrow(ShopifyGraphQLException::class);
});

it('throws ShopifyTransportException on non-2xx HTTP status', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response('Internal Server Error', 500),
    ]);

    expect(fn () => $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }'))
        ->toThrow(ShopifyTransportException::class);
});

it('sends the access token in the X-Shopify-Access-Token header', function () {
    Http::fake([
        "https://{$this->shop}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['ok' => true],
            'extensions' => ['cost' => ['requestedQueryCost' => 1, 'actualQueryCost' => 1, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 999, 'restoreRate' => 100]]],
        ], 200),
    ]);

    $this->client->graphql($this->shop, $this->token, $this->version, 'query { ok }');

    Http::assertSent(function ($request) {
        return $request->header('X-Shopify-Access-Token')[0] === 'shpat_test';
    });
});
