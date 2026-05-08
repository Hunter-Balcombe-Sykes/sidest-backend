<?php

use App\Http\Controllers\Api\Professional\Store\ShareCheckoutLinkController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// --- Helpers ---

function makeCheckoutRequest(array $body, ?Professional $pro = null): Request
{
    $professional = $pro ?? new Professional([
        'id' => (string) Str::uuid(),
        'handle' => 'test-affiliate',
        'professional_type' => 'influencer',
        'status' => 'active',
    ]);

    $request = Request::create('/api/share/checkout-link', 'POST', $body);
    $request->attributes->set('professional', $professional);

    return $request;
}

function fakeIntegration(string $shopDomain = 'test-store.myshopify.com', string $storefrontToken = 'sf-token'): ProfessionalIntegration
{
    return new ProfessionalIntegration([
        'professional_id' => (string) Str::uuid(),
        'provider' => 'shopify',
        'storefront_token' => $storefrontToken,
        'provider_metadata' => ['shop_domain' => $shopDomain],
    ]);
}

function makeCheckoutLinkService(array $catalog, ProfessionalIntegration $integration): AffiliateProductCatalogService
{
    $service = Mockery::mock(AffiliateProductCatalogService::class)->makePartial();

    $service->shouldReceive('resolveAffiliateBrandIntegration')
        ->andReturn([
            'brand_professional_id' => (string) Str::uuid(),
            'integration' => $integration,
        ]);

    $service->shouldReceive('fetchActiveCatalog')
        ->andReturn($catalog);

    return $service;
}

// --- Tests ---

it('creates a checkout link for valid products', function () {
    Http::fake([
        'test-store.myshopify.com/*' => Http::response([
            'data' => [
                'cartCreate' => [
                    'cart' => [
                        'id' => 'gid://shopify/Cart/abc123',
                        'checkoutUrl' => 'https://test-store.myshopify.com/cart/c/abc123',
                    ],
                    'userErrors' => [],
                ],
            ],
        ]),
    ]);

    $catalog = [
        [
            'gid' => 'gid://shopify/Product/111',
            'title' => 'Test Product',
            'variants' => [
                ['gid' => 'gid://shopify/ProductVariant/1001', 'title' => 'Default', 'available_for_sale' => true],
            ],
        ],
    ];

    $service = makeCheckoutLinkService($catalog, fakeIntegration());
    $controller = new ShareCheckoutLinkController($service);

    $response = $controller->store(makeCheckoutRequest([
        'affiliate_slug' => 'test-affiliate',
        'line_items' => [
            ['product_gid' => 'gid://shopify/Product/111', 'quantity' => 1],
        ],
    ]));

    expect($response->status())->toBe(200);
    expect($response->getData(true)['checkout_url'])
        ->toBe('https://test-store.myshopify.com/cart/c/abc123');
});

it('returns 404 when affiliate slug does not match', function () {
    $service = Mockery::mock(AffiliateProductCatalogService::class);
    $controller = new ShareCheckoutLinkController($service);

    $response = $controller->store(makeCheckoutRequest([
        'affiliate_slug' => 'someone-else',
        'line_items' => [
            ['product_gid' => 'gid://shopify/Product/111', 'quantity' => 1],
        ],
    ]));

    expect($response->status())->toBe(404);
});

it('returns error when affiliate has no brand connection', function () {
    $service = Mockery::mock(AffiliateProductCatalogService::class);
    $service->shouldReceive('resolveAffiliateBrandIntegration')
        ->andThrow(new \RuntimeException('No brand connection found.', 404));

    $controller = new ShareCheckoutLinkController($service);

    $response = $controller->store(makeCheckoutRequest([
        'affiliate_slug' => 'test-affiliate',
        'line_items' => [
            ['product_gid' => 'gid://shopify/Product/111', 'quantity' => 1],
        ],
    ]));

    expect($response->status())->toBe(404);
    expect($response->getData(true)['message'])->toBe('No brand connection found.');
});

it('returns 503 when storefront is not configured', function () {
    $integration = fakeIntegration(shopDomain: ''); // empty domain

    $catalog = [
        [
            'gid' => 'gid://shopify/Product/111',
            'title' => 'Test Product',
            'variants' => [
                ['gid' => 'gid://shopify/ProductVariant/1001', 'title' => 'Default', 'available_for_sale' => true],
            ],
        ],
    ];

    $service = makeCheckoutLinkService($catalog, $integration);
    $controller = new ShareCheckoutLinkController($service);

    $response = $controller->store(makeCheckoutRequest([
        'affiliate_slug' => 'test-affiliate',
        'line_items' => [
            ['product_gid' => 'gid://shopify/Product/111', 'quantity' => 1],
        ],
    ]));

    expect($response->status())->toBe(503);
});

it('returns 422 when product is not in catalog', function () {
    $catalog = [
        [
            'gid' => 'gid://shopify/Product/999',
            'title' => 'Other Product',
            'variants' => [
                ['gid' => 'gid://shopify/ProductVariant/9999', 'title' => 'Default', 'available_for_sale' => true],
            ],
        ],
    ];

    $service = makeCheckoutLinkService($catalog, fakeIntegration());
    $controller = new ShareCheckoutLinkController($service);

    $response = $controller->store(makeCheckoutRequest([
        'affiliate_slug' => 'test-affiliate',
        'line_items' => [
            ['product_gid' => 'gid://shopify/Product/111', 'quantity' => 1],
        ],
    ]));

    expect($response->status())->toBe(422);
});

it('returns 422 when product has no variants', function () {
    $catalog = [
        [
            'gid' => 'gid://shopify/Product/111',
            'title' => 'Variantless Product',
            'variants' => [],
        ],
    ];

    $service = makeCheckoutLinkService($catalog, fakeIntegration());
    $controller = new ShareCheckoutLinkController($service);

    $response = $controller->store(makeCheckoutRequest([
        'affiliate_slug' => 'test-affiliate',
        'line_items' => [
            ['product_gid' => 'gid://shopify/Product/111', 'quantity' => 1],
        ],
    ]));

    expect($response->status())->toBe(422);
});

it('returns 502 when Shopify API returns errors', function () {
    Http::fake([
        'test-store.myshopify.com/*' => Http::response([
            'errors' => [['message' => 'Internal error']],
        ]),
    ]);

    $catalog = [
        [
            'gid' => 'gid://shopify/Product/111',
            'title' => 'Test Product',
            'variants' => [
                ['gid' => 'gid://shopify/ProductVariant/1001', 'title' => 'Default', 'available_for_sale' => true],
            ],
        ],
    ];

    $service = makeCheckoutLinkService($catalog, fakeIntegration());
    $controller = new ShareCheckoutLinkController($service);

    $response = $controller->store(makeCheckoutRequest([
        'affiliate_slug' => 'test-affiliate',
        'line_items' => [
            ['product_gid' => 'gid://shopify/Product/111', 'quantity' => 1],
        ],
    ]));

    expect($response->status())->toBe(502);
});

it('creates checkout with multiple line items', function () {
    Http::fake([
        'test-store.myshopify.com/*' => Http::response([
            'data' => [
                'cartCreate' => [
                    'cart' => [
                        'id' => 'gid://shopify/Cart/abc123',
                        'checkoutUrl' => 'https://test-store.myshopify.com/cart/c/abc123',
                    ],
                    'userErrors' => [],
                ],
            ],
        ]),
    ]);

    $catalog = [
        [
            'gid' => 'gid://shopify/Product/111',
            'title' => 'Product One',
            'variants' => [
                ['gid' => 'gid://shopify/ProductVariant/1001', 'title' => 'Default', 'available_for_sale' => true],
            ],
        ],
        [
            'gid' => 'gid://shopify/Product/222',
            'title' => 'Product Two',
            'variants' => [
                ['gid' => 'gid://shopify/ProductVariant/2001', 'title' => 'Default', 'available_for_sale' => true],
            ],
        ],
    ];

    $service = makeCheckoutLinkService($catalog, fakeIntegration());
    $controller = new ShareCheckoutLinkController($service);

    $response = $controller->store(makeCheckoutRequest([
        'affiliate_slug' => 'test-affiliate',
        'line_items' => [
            ['product_gid' => 'gid://shopify/Product/111', 'quantity' => 2],
            ['product_gid' => 'gid://shopify/Product/222', 'quantity' => 1],
        ],
    ]));

    expect($response->status())->toBe(200);

    // Verify the correct line items were sent to Shopify
    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        $lines = $body['variables']['input']['lines'] ?? [];

        return count($lines) === 2
            && $lines[0]['merchandiseId'] === 'gid://shopify/ProductVariant/1001'
            && $lines[0]['quantity'] === 2
            && $lines[1]['merchandiseId'] === 'gid://shopify/ProductVariant/2001'
            && $lines[1]['quantity'] === 1;
    });

    expect($response->getData(true)['checkout_url'])
        ->toBe('https://test-store.myshopify.com/cart/c/abc123');
});
