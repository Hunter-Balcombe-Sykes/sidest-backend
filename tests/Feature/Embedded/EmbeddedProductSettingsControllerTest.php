<?php

use App\Http\Controllers\Api\Internal\EmbeddedProductSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Backs sidest-product-settings Shopify admin UI extension.
// Mutations go through Admin GraphQL — Http::fake() captures payloads so we
// can assert the controller dispatched the right namespace/key without
// network I/O.

beforeEach(function () {
    setupProfessionalIntegrationsTable();
    setupBrandStoreSettingsTable();
    Http::preventStrayRequests();

    $this->controller = app(EmbeddedProductSettingsController::class);
    $this->brandId = (string) Str::uuid();
    $this->productGid = 'gid://shopify/Product/12345';
    $this->shopDomain = 'shop.myshopify.com';
});

function seedEmbeddedShopifyIntegration(string $brandId, string $shopDomain, array $metadata = []): void
{
    // access_token + storefront_token are 'encrypted' casts on the model —
    // raw inserts must pre-encrypt or every read throws DecryptException.
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'provider' => 'shopify',
        'access_token' => encrypt('shpat_test_token'),
        'storefront_token' => encrypt('storefront_test_token'),
        'provider_metadata' => json_encode(array_merge([
            'shop_domain' => $shopDomain,
        ], $metadata)),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function makeProductSettingsRequest(string $brandId, array $query = [], array $body = [], string $method = 'GET'): Request
{
    $request = Request::create('/internal/embedded/product-settings', $method, array_merge($query, $body));
    $request->attributes->set('embedded_professional_id', $brandId);

    return $request;
}

it('returns 422 when product_gid is missing on show()', function () {
    seedEmbeddedShopifyIntegration($this->brandId, $this->shopDomain);

    $response = $this->controller->show(makeProductSettingsRequest($this->brandId));

    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true)['message'])->toBe('product_gid is required.');
});

it('returns 404 when no Shopify integration exists for the brand', function () {
    // No integration row seeded.
    $request = makeProductSettingsRequest($this->brandId, ['product_gid' => $this->productGid]);
    $response = $this->controller->show($request);

    expect($response->getStatusCode())->toBe(404);
    expect(json_decode($response->getContent(), true)['message'])->toBe('No Shopify integration found.');
});

it('parses metafields + variants from a single GraphQL fetch', function () {
    seedEmbeddedShopifyIntegration($this->brandId, $this->shopDomain, [
        'favourites_collection_handle' => 'favourites',
    ]);

    Http::fake([
        // Admin GraphQL — productMetafields query
        "https://{$this->shopDomain}/admin/api/*" => Http::response([
            'data' => [
                'product' => [
                    'active' => ['value' => 'true'],
                    'commissionOverride' => ['value' => '0.15'],
                    'affiliateDiscountPct' => ['value' => null],
                    'customPhotosEnabled' => ['value' => 'false'],
                    'variants' => [
                        'edges' => [
                            ['node' => [
                                'id' => 'gid://shopify/ProductVariant/1',
                                'title' => 'Small',
                                'enabled' => ['value' => 'true'],
                            ]],
                            ['node' => [
                                'id' => 'gid://shopify/ProductVariant/2',
                                'title' => 'Large',
                                'enabled' => ['value' => 'false'],
                            ]],
                        ],
                    ],
                ],
            ],
        ], 200),
        // Storefront API — isInCollection lookup
        "https://{$this->shopDomain}/api/*" => Http::response([
            'data' => [
                'collection' => ['products' => ['edges' => [['node' => ['id' => $this->productGid]]]]],
            ],
        ], 200),
    ]);

    $request = makeProductSettingsRequest($this->brandId, ['product_gid' => $this->productGid]);
    $data = json_decode($this->controller->show($request)->getContent(), true);

    expect($data['active'])->toBeTrue();
    expect($data['commission_override'])->toBe(0.15);
    expect($data['affiliate_discount_pct'])->toBeNull();
    expect($data['custom_photos_enabled'])->toBeFalse();
    expect($data['variants'])->toHaveCount(2);
    expect($data['variants'][0])->toMatchArray([
        'gid' => 'gid://shopify/ProductVariant/1',
        'title' => 'Small',
        'enabled' => true,
    ]);
    expect($data['variants'][1]['enabled'])->toBeFalse();
    expect($data['in_favourites_collection'])->toBeTrue();
});

it('returns 422 from update() when product_gid or field is missing', function () {
    seedEmbeddedShopifyIntegration($this->brandId, $this->shopDomain);

    $request = makeProductSettingsRequest($this->brandId, [], ['product_gid' => $this->productGid], 'PATCH');
    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(422);
});

it('saves a boolean metafield via Admin GraphQL with correct namespace/key/type', function () {
    seedEmbeddedShopifyIntegration($this->brandId, $this->shopDomain);

    Http::fake([
        "https://{$this->shopDomain}/admin/api/*" => Http::sequence()
            // findMetafield — return no existing metafield → triggers productUpdate create path.
            ->push(['data' => ['product' => ['metafield' => null]]], 200)
            // productUpdate — success with no userErrors.
            ->push(['data' => ['productUpdate' => ['product' => ['id' => $this->productGid], 'userErrors' => []]]], 200),
    ]);

    $request = makeProductSettingsRequest($this->brandId, [], [
        'product_gid' => $this->productGid,
        'field' => 'active',
        'value' => false,
    ], 'PATCH');
    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(200);

    // Inspect captured request payloads.
    Http::assertSent(function ($req) {
        $body = json_decode($req->body(), true);
        if (! str_contains($req->url(), '/admin/api/')) {
            return false;
        }
        // The productUpdate mutation embeds metafield namespace=partna, key=active, type=boolean.
        if (! isset($body['variables']['input']['metafields'])) {
            return false;
        }
        $mf = $body['variables']['input']['metafields'][0];

        return $mf['namespace'] === 'partna'
            && $mf['key'] === 'active'
            && $mf['type'] === 'boolean'
            && $mf['value'] === json_encode(false);
    });
});

it('returns 422 when the saveMetafield mutation returns userErrors', function () {
    seedEmbeddedShopifyIntegration($this->brandId, $this->shopDomain);

    Http::fake([
        "https://{$this->shopDomain}/admin/api/*" => Http::sequence()
            ->push(['data' => ['product' => ['metafield' => null]]], 200)
            ->push([
                'data' => [
                    'productUpdate' => [
                        'product' => null,
                        'userErrors' => [['field' => ['metafields'], 'message' => 'Invalid value']],
                    ],
                ],
            ], 200),
    ]);

    $request = makeProductSettingsRequest($this->brandId, [], [
        'product_gid' => $this->productGid,
        'field' => 'commission_override',
        'value' => 0.25,
    ], 'PATCH');
    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true)['message'])->toBe('Invalid value');
});

it('rejects toggleCollection when the brand has no favourites collection handle', function () {
    // Integration row exists but provider_metadata has no collection handle.
    seedEmbeddedShopifyIntegration($this->brandId, $this->shopDomain);

    $request = makeProductSettingsRequest($this->brandId, [], [
        'product_gid' => $this->productGid,
        'field' => 'add_to_favourites',
        'value' => true,
    ], 'PATCH');
    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true)['message'])
        ->toBe('Collection has not been created yet.');
});

it('saves variant enabled states only for variants whose state actually changed', function () {
    seedEmbeddedShopifyIntegration($this->brandId, $this->shopDomain);

    Http::fake([
        "https://{$this->shopDomain}/admin/api/*" => Http::sequence()
            // fetchVariants response — variant 1 currently enabled, variant 2 enabled.
            ->push([
                'data' => [
                    'product' => [
                        'variants' => [
                            'edges' => [
                                ['node' => [
                                    'id' => 'gid://shopify/ProductVariant/1',
                                    'title' => 'Small',
                                    'enabled' => ['value' => 'true'],
                                ]],
                                ['node' => [
                                    'id' => 'gid://shopify/ProductVariant/2',
                                    'title' => 'Large',
                                    'enabled' => ['value' => 'true'],
                                ]],
                            ],
                        ],
                    ],
                ],
            ], 200)
            // productVariantUpdate mutation for variant 2 (the one being disabled).
            ->push(['data' => ['productVariantUpdate' => ['productVariant' => ['id' => 'gid://shopify/ProductVariant/2'], 'userErrors' => []]]], 200),
    ]);

    $request = makeProductSettingsRequest($this->brandId, [], [
        'product_gid' => $this->productGid,
        'field' => 'disabled_variant_gids',
        'value' => ['gid://shopify/ProductVariant/2'],
    ], 'PATCH');
    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(200);

    // Exactly two requests: one variants fetch + one variant update (variant 1 was already enabled).
    Http::assertSentCount(2);
});

it('returns 422 on an unknown field', function () {
    seedEmbeddedShopifyIntegration($this->brandId, $this->shopDomain);

    $request = makeProductSettingsRequest($this->brandId, [], [
        'product_gid' => $this->productGid,
        'field' => 'totally_made_up',
        'value' => 'whatever',
    ], 'PATCH');
    $response = $this->controller->update($request);

    expect($response->getStatusCode())->toBe(422);
    expect(json_decode($response->getContent(), true)['message'])->toContain('Unknown field');
});
