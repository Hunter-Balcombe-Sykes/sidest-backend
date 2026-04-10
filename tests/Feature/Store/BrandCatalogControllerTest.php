<?php

use App\Http\Controllers\Api\Professional\Store\BrandCatalogController;
use App\Http\Requests\Api\Professional\Store\ToggleProductActiveRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductCommissionRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductDiscountRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductMetafieldsRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function makeBrandCatalogRequest(string $method = 'GET', array $params = [], ?Professional $pro = null): Request
{
    $professional = $pro ?? new Professional([
        'id' => (string) Str::uuid(),
        'professional_type' => 'brand',
        'status' => 'active',
    ]);

    $request = Request::create('/api/test', $method, $params);
    $request->attributes->set('professional', $professional);

    return $request;
}

function makeNonBrandCatalogRequest(string $method = 'GET', array $params = []): Request
{
    $professional = new Professional([
        'id' => (string) Str::uuid(),
        'professional_type' => 'influencer',
        'status' => 'active',
    ]);

    $request = Request::create('/api/test', $method, $params);
    $request->attributes->set('professional', $professional);

    return $request;
}

function fakeBrandCatalog(): array
{
    return [
        [
            'gid' => 'gid://shopify/Product/111',
            'title' => 'Product One',
            'handle' => 'product-one',
            'status' => 'ACTIVE',
            'featured_image' => ['url' => 'https://example.com/img.jpg', 'altText' => null],
            'price_range' => [
                'min' => ['amount' => '10.00', 'currencyCode' => 'AUD'],
                'max' => ['amount' => '20.00', 'currencyCode' => 'AUD'],
            ],
            'variants' => [],
            'metafields' => [
                'active' => true,
                'commission_override' => 25.0,
                'affiliate_discount_pct' => null,
            ],
        ],
    ];
}

// --- Authorization ---

it('returns 403 when non-brand tries to list catalog', function () {
    $service = app(BrandCatalogService::class);
    $controller = new BrandCatalogController($service);

    $response = $controller->index(makeNonBrandCatalogRequest());

    expect($response->status())->toBe(403);
    expect($response->getData(true)['message'])->toContain('brand accounts');
});

it('returns 403 when non-brand tries to toggle active', function () {
    $service = app(BrandCatalogService::class);
    $controller = new BrandCatalogController($service);

    $request = makeNonBrandCatalogRequest('PATCH', ['active' => true]);
    $formRequest = ToggleProductActiveRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->toggleActive($formRequest, 'gid://shopify/Product/123');

    expect($response->status())->toBe(403);
});

it('returns 403 when non-brand tries to update commission', function () {
    $service = app(BrandCatalogService::class);
    $controller = new BrandCatalogController($service);

    $request = makeNonBrandCatalogRequest('PATCH', ['commission_override' => 25]);
    $formRequest = UpdateProductCommissionRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->updateCommission($formRequest, 'gid://shopify/Product/123');

    expect($response->status())->toBe(403);
});

it('returns 403 when non-brand tries to update discount', function () {
    $service = app(BrandCatalogService::class);
    $controller = new BrandCatalogController($service);

    $request = makeNonBrandCatalogRequest('PATCH', ['affiliate_discount_pct' => 10]);
    $formRequest = UpdateProductDiscountRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->updateDiscount($formRequest, 'gid://shopify/Product/123');

    expect($response->status())->toBe(403);
});

// --- Catalog listing ---

it('returns product catalog with metafield values for brand', function () {
    $catalog = fakeBrandCatalog();
    $service = Mockery::mock(BrandCatalogService::class);

    $service->shouldReceive('fetchBrandCatalog')->andReturn($catalog);

    $controller = new BrandCatalogController($service);

    $response = $controller->index(makeBrandCatalogRequest());

    expect($response->status())->toBe(200);

    $data = $response->getData(true);
    expect($data)->toHaveKey('products');
    expect($data['products'])->toHaveCount(1);
    expect($data['products'][0]['gid'])->toBe('gid://shopify/Product/111');
    expect($data['products'][0]['metafields']['active'])->toBeTrue();
    expect((float) $data['products'][0]['metafields']['commission_override'])->toBe(25.0);
});

// --- Error handling ---

it('returns 422 when brand has no Shopify integration', function () {
    $service = Mockery::mock(BrandCatalogService::class);
    $service->shouldReceive('fetchBrandCatalog')
        ->andThrow(new \RuntimeException('Your Shopify store is not connected.', 422));

    $controller = new BrandCatalogController($service);

    $response = $controller->index(makeBrandCatalogRequest());

    expect($response->status())->toBe(422);
});

it('returns 502 on Shopify API failure', function () {
    $service = Mockery::mock(BrandCatalogService::class);
    $service->shouldReceive('fetchBrandCatalog')
        ->andThrow(new \Exception('Connection timed out'));

    $controller = new BrandCatalogController($service);

    $response = $controller->index(makeBrandCatalogRequest());

    expect($response->status())->toBe(502);
    expect($response->getData(true)['message'])->toContain('Unable to reach Shopify');
});

// --- GID validation ---

it('returns 422 for invalid product GID format on toggle active', function () {
    $service = Mockery::mock(BrandCatalogService::class);
    $controller = new BrandCatalogController($service);

    $request = makeBrandCatalogRequest('PATCH', ['active' => true]);
    $formRequest = ToggleProductActiveRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->toggleActive($formRequest, 'not-a-valid-gid');

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('Invalid product GID');
});

// --- Form request validation ---

it('validates toggle active request requires boolean', function () {
    $request = new ToggleProductActiveRequest();
    $rules = $request->rules();

    expect($rules['active'])->toContain('required');
    expect($rules['active'])->toContain('boolean');
});

it('validates commission request allows nullable numeric', function () {
    $request = new UpdateProductCommissionRequest();
    $rules = $request->rules();

    expect($rules['commission_override'])->toContain('nullable');
    expect($rules['commission_override'])->toContain('numeric');
});

it('validates metafields request allows all three fields', function () {
    $request = new UpdateProductMetafieldsRequest();
    $rules = $request->rules();

    expect($rules)->toHaveKey('active');
    expect($rules)->toHaveKey('commission_override');
    expect($rules)->toHaveKey('affiliate_discount_pct');
});
