<?php

use App\Http\Controllers\Api\Professional\Store\BrandCollectionController;
use App\Http\Requests\Api\Professional\Store\ManageCollectionProductsRequest;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function makeBrandCollectionRequest(string $method = 'GET', array $params = [], ?string $type = 'brand'): Request
{
    $professional = new Professional([
        'id' => (string) Str::uuid(),
        'professional_type' => $type,
        'status' => 'active',
    ]);

    $request = Request::create('/api/test', $method, $params);
    $request->attributes->set('professional', $professional);

    return $request;
}

it('returns 403 when non-brand tries to list collection products', function () {
    $service = app(BrandCatalogService::class);
    $controller = new BrandCollectionController($service);

    $response = $controller->index(makeBrandCollectionRequest('GET', [], 'influencer'), 'default');

    expect($response->status())->toBe(403);
    expect($response->getData(true)['message'])->toContain('brand accounts');
});

it('returns 403 when non-brand tries to add products to collection', function () {
    $service = app(BrandCatalogService::class);
    $controller = new BrandCollectionController($service);

    $request = makeBrandCollectionRequest('POST', ['product_gids' => ['gid://shopify/Product/123']], 'influencer');
    $formRequest = ManageCollectionProductsRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->addProducts($formRequest, 'default');

    expect($response->status())->toBe(403);
});

it('returns 403 when non-brand tries to remove products from collection', function () {
    $service = app(BrandCatalogService::class);
    $controller = new BrandCollectionController($service);

    $request = makeBrandCollectionRequest('DELETE', ['product_gids' => ['gid://shopify/Product/123']], 'influencer');
    $formRequest = ManageCollectionProductsRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->removeProducts($formRequest, 'default');

    expect($response->status())->toBe(403);
});

it('lists collection products for brand', function () {
    $service = Mockery::mock(BrandCatalogService::class);
    $brandId = (string) Str::uuid();

    $service->shouldReceive('resolveBrandIntegration')
        ->andReturn([
            'integration' => new ProfessionalIntegration,
            'shop_domain' => 'test.myshopify.com',
            'access_token' => 'test-token',
            'metadata' => ['default_collection_handle' => 'sidest-default-products'],
        ]);

    $service->shouldReceive('resolveCollectionGid')
        ->andReturn('gid://shopify/Collection/999');

    $service->shouldReceive('fetchCollectionProducts')
        ->andReturn([
            ['gid' => 'gid://shopify/Product/111', 'title' => 'Product A', 'handle' => 'product-a', 'featured_image' => null],
        ]);

    $controller = new BrandCollectionController($service);
    $response = $controller->index(makeBrandCollectionRequest(), 'default');

    expect($response->status())->toBe(200);
    $data = $response->getData(true);
    expect($data['products'])->toHaveCount(1);
    expect($data['products'][0]['gid'])->toBe('gid://shopify/Product/111');
});

it('validates ManageCollectionProductsRequest requires product GIDs array', function () {
    $request = new ManageCollectionProductsRequest;
    $rules = $request->rules();

    expect($rules['product_gids'])->toContain('required');
    expect($rules['product_gids'])->toContain('array');
    expect($rules)->toHaveKey('product_gids.*');
});

it('validates ManageCollectionProductsRequest caps at 50 items', function () {
    $request = new ManageCollectionProductsRequest;
    $rules = $request->rules();

    expect($rules['product_gids'])->toContain('max:50');
});
