<?php

use App\Http\Controllers\Api\Professional\Store\AffiliateProductController;
use App\Http\Requests\Api\Professional\Store\ReorderSelectionsRequest;
use App\Http\Requests\Api\Professional\Store\StoreSelectionRequest;
use App\Http\Requests\Api\Professional\Store\UpdateSelectionVariantsRequest;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// --- Helpers ---

function makeAffiliateRequest(string $method = 'GET', array $params = [], ?Professional $pro = null): Request
{
    $professional = $pro ?? new Professional([
        'id' => (string) Str::uuid(),
        'professional_type' => 'influencer',
        'status' => 'active',
    ]);

    $request = Request::create('/api/test', $method, $params);
    $request->attributes->set('professional', $professional);

    return $request;
}

function makeBrandRequest(string $method = 'GET', array $params = []): Request
{
    $professional = new Professional([
        'id' => (string) Str::uuid(),
        'professional_type' => 'brand',
        'status' => 'active',
    ]);

    $request = Request::create('/api/test', $method, $params);
    $request->attributes->set('professional', $professional);

    return $request;
}

function fakeStorefrontProducts(): array
{
    return [
        [
            'gid' => 'gid://shopify/Product/111',
            'title' => 'Test Product One',
            'handle' => 'test-product-one',
            'available_for_sale' => true,
            'featured_image' => ['url' => 'https://example.com/img1.jpg', 'altText' => null],
            'price_range' => [
                'min' => ['amount' => '10.00', 'currencyCode' => 'AUD'],
                'max' => ['amount' => '20.00', 'currencyCode' => 'AUD'],
            ],
            'variants' => [
                ['gid' => 'gid://shopify/ProductVariant/1001', 'title' => 'Default', 'available_for_sale' => true, 'price' => ['amount' => '10.00', 'currencyCode' => 'AUD']],
            ],
        ],
        [
            'gid' => 'gid://shopify/Product/222',
            'title' => 'Test Product Two',
            'handle' => 'test-product-two',
            'available_for_sale' => true,
            'featured_image' => null,
            'price_range' => [
                'min' => ['amount' => '5.00', 'currencyCode' => 'AUD'],
                'max' => ['amount' => '5.00', 'currencyCode' => 'AUD'],
            ],
            'variants' => [],
        ],
    ];
}

function makeServiceWithCatalog(array $catalog): AffiliateProductCatalogService
{
    $service = Mockery::mock(AffiliateProductCatalogService::class)->makePartial();

    $service->shouldReceive('resolveAffiliateBrandIntegration')
        ->andReturn([
            'brand_professional_id' => (string) Str::uuid(),
            'integration' => new ProfessionalIntegration,
        ]);

    $service->shouldReceive('fetchActiveCatalog')
        ->andReturn($catalog);

    $service->shouldReceive('isProductInCatalog')
        ->andReturnUsing(fn ($brandId, $gid) => collect($catalog)->contains('gid', $gid));

    return $service;
}

// --- Brand account rejection ---

it('returns 403 when brand account tries to list products', function () {
    $service = app(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($service);

    $response = $controller->index(makeBrandRequest());

    expect($response->status())->toBe(403);
    expect($response->getData(true)['message'])->toContain('Brand accounts');
});

it('returns 403 when brand account tries to create selection', function () {
    $service = app(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($service);

    $request = makeBrandRequest('POST', ['product_gid' => 'gid://shopify/Product/123', 'sort_order' => 0]);
    $formRequest = StoreSelectionRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->store($formRequest);

    expect($response->status())->toBe(403);
});

it('returns 403 when brand account tries to delete selection', function () {
    $service = app(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($service);

    $response = $controller->destroy(makeBrandRequest('DELETE'), 'gid://shopify/Product/123');

    expect($response->status())->toBe(403);
});

it('returns 403 when brand account tries to reorder selections', function () {
    $service = app(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($service);

    $request = makeBrandRequest('PATCH', ['items' => [['product_gid' => 'gid://shopify/Product/123', 'sort_order' => 0]]]);
    $formRequest = ReorderSelectionsRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->reorder($formRequest);

    expect($response->status())->toBe(403);
});

// --- Catalog service error handling ---

it('returns 404 when affiliate has no brand connection', function () {
    $service = Mockery::mock(AffiliateProductCatalogService::class);
    $service->shouldReceive('getCatalogWithSelections')
        ->andThrow(new \RuntimeException('No brand connection found.', 404));

    $controller = new AffiliateProductController($service);

    $response = $controller->index(makeAffiliateRequest());

    expect($response->status())->toBe(404);
    expect($response->getData(true)['message'])->toBe('No brand connection found.');
});

it('returns 422 when brand has no Shopify integration', function () {
    $service = Mockery::mock(AffiliateProductCatalogService::class);
    $service->shouldReceive('getCatalogWithSelections')
        ->andThrow(new \RuntimeException('Brand does not have a connected Shopify store.', 422));

    $controller = new AffiliateProductController($service);

    $response = $controller->index(makeAffiliateRequest());

    expect($response->status())->toBe(422);
});

it('returns 502 on Shopify API failure', function () {
    $service = Mockery::mock(AffiliateProductCatalogService::class);
    $service->shouldReceive('getCatalogWithSelections')
        ->andThrow(new \Exception('Connection timed out'));

    $controller = new AffiliateProductController($service);

    $response = $controller->index(makeAffiliateRequest());

    expect($response->status())->toBe(502);
    expect($response->getData(true)['message'])->toContain('Unable to reach');
});

// --- Catalog listing ---

it('returns product catalog with selection flags', function () {
    $catalog = fakeStorefrontProducts();
    $service = Mockery::mock(AffiliateProductCatalogService::class);
    $brandId = (string) Str::uuid();

    $service->shouldReceive('getCatalogWithSelections')
        ->andReturn([
            'products' => array_map(fn ($p) => array_merge($p, ['selected' => false, 'sort_order' => null]), $catalog),
            'brand_professional_id' => $brandId,
        ]);

    $controller = new AffiliateProductController($service);

    $response = $controller->index(makeAffiliateRequest());

    expect($response->status())->toBe(200);

    $data = $response->getData(true);
    expect($data)->toHaveKey('products');
    expect($data['products'])->toHaveCount(2);
    expect($data['products'][0]['gid'])->toBe('gid://shopify/Product/111');
    expect($data['products'][0]['selected'])->toBeFalse();
    expect($data['brand_professional_id'])->toBe($brandId);
});

// --- Delete ---

it('returns 422 for invalid GID format on delete', function () {
    $service = app(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($service);

    $response = $controller->destroy(makeAffiliateRequest('DELETE'), 'not-a-valid-gid');

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('Invalid product GID');
});

it('returns 422 when deleting with invalid GID format', function () {
    // The test name was previously "returns 404 when deleting non-existent
    // selection" but the test only ever exercises the GID-format validation
    // path (it passes 'not-a-gid' which gets rejected before the query runs).
    // Renamed to match what's actually being tested.
    $service = app(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($service);

    $pro = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'influencer', 'status' => 'active']);

    $response = $controller->destroy(makeAffiliateRequest('DELETE', [], $pro), 'not-a-gid');

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('Invalid product GID');
});

// --- Stale selections ---

it('returns stale selections when catalog changes', function () {
    $service = Mockery::mock(AffiliateProductCatalogService::class);
    $service->shouldReceive('getStaleSelections')
        ->andReturn(collect([]));

    $controller = new AffiliateProductController($service);

    $response = $controller->stale(makeAffiliateRequest());

    expect($response->status())->toBe(200);
    expect($response->getData(true))->toHaveKey('stale');
});

// --- Form Request validation ---

it('validates product_gid format in StoreSelectionRequest', function () {
    $request = new StoreSelectionRequest;
    $rules = $request->rules();

    expect($rules['product_gid'])->toContain('required');
    expect($rules['product_gid'])->toContain('string');
    expect($rules['sort_order'])->toContain('integer');
});

it('validates items array in ReorderSelectionsRequest', function () {
    $request = new ReorderSelectionsRequest;
    $rules = $request->rules();

    expect($rules['items'])->toContain('required');
    expect($rules['items'])->toContain('array');
    expect($rules)->toHaveKey('items.*.product_gid');
    expect($rules)->toHaveKey('items.*.sort_order');
});

// --- Per-selection variant picker ---

it('validates shape in UpdateSelectionVariantsRequest', function () {
    $request = new UpdateSelectionVariantsRequest;
    $rules = $request->rules();

    // brand_professional_id is now derived server-side — not accepted from the client
    expect($rules)->not->toHaveKey('brand_professional_id');
    expect($rules['variant_gids'])->toContain('sometimes');
    expect($rules['variant_gids'])->toContain('nullable');
    expect($rules['variant_gids'])->toContain('array');
    expect($rules)->toHaveKey('variant_gids.*');
});

it('rejects 403 on updateVariants for brand accounts', function () {
    $service = app(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($service);

    $request = makeBrandRequest('PATCH', [
        'variant_gids' => null,
    ]);
    $formRequest = UpdateSelectionVariantsRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->updateVariants($formRequest, 'gid://shopify/Product/123');

    expect($response->status())->toBe(403);
});

it('rejects malformed product GID on updateVariants', function () {
    $service = app(AffiliateProductCatalogService::class);
    $controller = new AffiliateProductController($service);

    $request = makeAffiliateRequest('PATCH', [
        'variant_gids' => null,
    ]);
    $formRequest = UpdateSelectionVariantsRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));
    $formRequest->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

    $response = $controller->updateVariants($formRequest, 'not-a-product-gid');

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('Invalid product GID');
});
