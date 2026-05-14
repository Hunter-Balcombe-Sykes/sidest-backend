<?php

use App\Exceptions\Shopify\ShopifyGraphQLException;
use App\Exceptions\Shopify\ShopifyTransportException;
use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\ShopDomain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::del('shopify:bucket:test.myshopify.com');
    Redis::del('shopify:cost:'.sha1('query { shop { id } }'));
    Redis::del('shopify:cost:'.sha1('query { foo }'));
    Redis::del('shopify:cost:'.sha1('query { ok }'));
    Redis::del('shopify:cost:'.sha1('query test { ok }'));
    $this->client = app(ShopifyAdminClient::class);
    $this->shopName = 'test.myshopify.com';
    $this->shop = ShopDomain::fromUntrusted($this->shopName);
    $this->token = 'shpat_test';
    $this->version = '2025-01';
});

it('returns the Response object on a successful GraphQL call', function () {
    Http::fake([
        "https://{$this->shopName}/admin/api/{$this->version}/graphql.json" => Http::response([
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
        "https://{$this->shopName}/admin/api/{$this->version}/graphql.json" => Http::response([
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
        "https://{$this->shopName}/admin/api/{$this->version}/graphql.json" => Http::response('Internal Server Error', 500),
    ]);

    expect(fn () => $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }'))
        ->toThrow(ShopifyTransportException::class);
});

it('sends the access token in the X-Shopify-Access-Token header', function () {
    Http::fake([
        "https://{$this->shopName}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['ok' => true],
            'extensions' => ['cost' => ['requestedQueryCost' => 1, 'actualQueryCost' => 1, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 999, 'restoreRate' => 100]]],
        ], 200),
    ]);

    $this->client->graphql($this->shop, $this->token, $this->version, 'query { ok }');

    Http::assertSent(function ($request) {
        return $request->header('X-Shopify-Access-Token')[0] === 'shpat_test';
    });
});

it('reconciles the local bucket from throttleStatus after each response', function () {
    Http::fake([
        "https://{$this->shopName}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['ok' => true],
            'extensions' => [
                'cost' => [
                    'requestedQueryCost' => 100,
                    'actualQueryCost' => 20,
                    'throttleStatus' => [
                        'maximumAvailable' => 1000,
                        'currentlyAvailable' => 450,
                        'restoreRate' => 100,
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->client->graphql($this->shop, $this->token, $this->version, 'query { ok }');

    // After reconcile, local bucket should report 450 available.
    $tracker = app(\App\Services\Shopify\Client\ShopifyBudgetTracker::class);
    $result = $tracker->tryAcquire($this->shopName, 50, 1000, 100);
    expect($result['remaining'])->toBe(400);
});

it('records actual cost for future query estimates', function () {
    Http::fake([
        "https://{$this->shopName}/admin/api/{$this->version}/graphql.json" => Http::response([
            'data' => ['ok' => true],
            'extensions' => [
                'cost' => [
                    'requestedQueryCost' => 100,
                    'actualQueryCost' => 15,
                    'throttleStatus' => [
                        'maximumAvailable' => 1000,
                        'currentlyAvailable' => 985,
                        'restoreRate' => 100,
                    ],
                ],
            ],
        ], 200),
    ]);

    $query = 'query test { ok }';
    for ($i = 0; $i < 5; $i++) {
        $this->client->graphql($this->shop, $this->token, $this->version, $query);
    }

    $costTracker = app(\App\Services\Shopify\Client\ShopifyCostTracker::class);
    expect($costTracker->estimate(sha1($query), 100))->toBeLessThanOrEqual(20);
});

it('retries in-process on a THROTTLED response and succeeds when budget recovers', function () {
    Http::fake([
        "https://{$this->shopName}/admin/api/{$this->version}/graphql.json" => Http::sequence()
            ->push([
                'errors' => [
                    ['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']],
                ],
                'extensions' => [
                    'cost' => [
                        'throttleStatus' => [
                            'maximumAvailable' => 1000,
                            'currentlyAvailable' => 0,
                            'restoreRate' => 100,
                        ],
                    ],
                ],
            ], 200)
            ->push([
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
    Http::assertSentCount(2);
});

it('throws ShopifyThrottledException after the single immediate retry (no in-process sleep)', function () {
    // Master Pattern 17 / DB-D#SCALE-3: the THROTTLED path now bubbles the
    // retry delay to the queue's backoff() instead of tying up a worker with
    // usleep. We expect exactly two HTTP calls — the initial attempt + one
    // immediate retry — before the client throws.
    Http::fake([
        "https://{$this->shopName}/admin/api/{$this->version}/graphql.json" => Http::response([
            'errors' => [
                ['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']],
            ],
            'extensions' => [
                'cost' => [
                    'throttleStatus' => [
                        'maximumAvailable' => 1000,
                        'currentlyAvailable' => 0,
                        'restoreRate' => 100,
                    ],
                ],
            ],
        ], 200),
    ]);

    try {
        $this->client->graphql($this->shop, $this->token, $this->version, 'query { shop { id } }');
        $this->fail('Expected ShopifyThrottledException was not thrown');
    } catch (\App\Exceptions\Shopify\ShopifyThrottledException $e) {
        expect($e->attempts)->toBe(1);
        expect($e->waitMs)->toBeGreaterThan(0);
    }

    // 1 initial attempt + 1 immediate retry = 2 total calls.
    Http::assertSentCount(2);
});
