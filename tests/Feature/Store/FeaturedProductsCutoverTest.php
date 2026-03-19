<?php

use App\Http\Controllers\Api\Professional\Store\FeaturedProductsController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Store\BrandProductCatalogService;
use App\Services\Store\FeaturedProductsPayloadService;
use Illuminate\Http\Request;

it('rejects legacy featured-products payload after hard cutover', function () {
    $controller = new FeaturedProductsController(
        \Mockery::mock(FeaturedProductsPayloadService::class),
        \Mockery::mock(BrandProductCatalogService::class)
    );

    $professional = new Professional([
        'id' => '11111111-1111-1111-1111-111111111111',
    ]);
    $professional->setRelation('site', new Site([
        'id' => '22222222-2222-2222-2222-222222222222',
    ]));

    $request = Request::create('/api/store/featured-products', 'PUT', [
        'products' => [
            ['shopify_product_id' => 'gid://shopify/Product/123'],
        ],
    ]);
    $request->attributes->set('professional', $professional);

    $response = $controller->update($request);

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'] ?? null)->toBe(
        'Legacy featured-products payload is no longer supported. Use selected_products[{brand_product_id, sort_order}].'
    );
});
