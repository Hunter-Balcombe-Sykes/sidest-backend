<?php

use App\Exceptions\Shopify\ShopifyGraphQLException;
use App\Exceptions\Shopify\ShopifyThrottledException;
use App\Exceptions\Shopify\ShopifyTransportException;
use App\Services\Shopify\Client\ShopifyStorefrontClient;
use App\Services\Shopify\ShopDomain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

// Locks down the public contract of the Storefront client mirror of
// ShopifyAdminClient: budget-aware, THROTTLED bubbles after one immediate
// retry, GraphQL errors throw, non-2xx transport throws. Master Pattern 17 /
// DB-D#SCALE-1.

beforeEach(function () {
    // Storefront bucket lives under a different prefix than Admin.
    Redis::del('shopify:storefront-bucket:test.myshopify.com');
    Redis::del('shopify:cost:'.sha1('query { shop { id } }'));
    Redis::del('shopify:cost:'.sha1('query { foo }'));
    Redis::del('shopify:cost:'.sha1('query { ok }'));

    $this->client = app(ShopifyStorefrontClient::class);
    $this->shopName = 'test.myshopify.com';
    $this->shop = ShopDomain::fromUntrusted($this->shopName);
    $this->token = 'shpsa_storefront_test';
    $this->version = '2025-01';
});

it('returns the Response object on a successful Storefront GraphQL call', function () {
    Http::fake([
        "https://{$this->shopName}/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['shop' => ['id' => 'gid://shopify/Shop/1']],
        ], 200),
    ]);

    $response = $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }');

    expect($response->json('data.shop.id'))->toBe('gid://shopify/Shop/1');
});

it('sends the storefront token in the X-Shopify-Storefront-Access-Token header', function () {
    Http::fake([
        "https://{$this->shopName}/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['ok' => true],
        ], 200),
    ]);

    $this->client->graphql($this->shop, $this->token, $this->version, 'query { ok }');

    Http::assertSent(function ($request) {
        return $request->header('X-Shopify-Storefront-Access-Token')[0] === 'shpsa_storefront_test';
    });
});

it('targets the Storefront /api/ endpoint, not /admin/api/', function () {
    Http::fake([
        '*' => Http::response(['data' => ['ok' => true]], 200),
    ]);

    $this->client->graphql($this->shop, $this->token, $this->version, 'query { ok }');

    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), "/api/{$this->version}/graphql.json")
            && ! str_contains((string) $request->url(), '/admin/');
    });
});

it('throws ShopifyGraphQLException on non-throttle top-level errors', function () {
    Http::fake([
        "https://{$this->shopName}/api/{$this->version}/graphql.json" => Http::response([
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
        "https://{$this->shopName}/api/{$this->version}/graphql.json" => Http::response('Internal Server Error', 500),
    ]);

    expect(fn () => $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }'))
        ->toThrow(ShopifyTransportException::class);
});

it('retries in-process once on a THROTTLED response and succeeds when recovered', function () {
    Http::fake([
        "https://{$this->shopName}/api/{$this->version}/graphql.json" => Http::sequence()
            ->push([
                'errors' => [
                    ['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']],
                ],
            ], 200)
            ->push([
                'data' => ['shop' => ['id' => 'gid://shopify/Shop/1']],
            ], 200),
    ]);

    $response = $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }');

    expect($response->json('data.shop.id'))->toBe('gid://shopify/Shop/1');
    Http::assertSentCount(2);
});

it('throws ShopifyThrottledException after the single immediate retry (no in-process sleep)', function () {
    Http::fake([
        "https://{$this->shopName}/api/{$this->version}/graphql.json" => Http::response([
            'errors' => [
                ['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']],
            ],
        ], 200),
    ]);

    try {
        $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }');
        $this->fail('Expected ShopifyThrottledException was not thrown');
    } catch (ShopifyThrottledException $e) {
        expect($e->attempts)->toBe(1);
    }

    // 1 initial attempt + 1 immediate retry = 2 total calls.
    Http::assertSentCount(2);
});
