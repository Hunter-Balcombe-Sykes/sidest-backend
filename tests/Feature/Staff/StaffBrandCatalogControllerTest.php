<?php

use App\Http\Controllers\Api\Professional\Store\BrandCatalogController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandCatalogController;
use App\Http\Requests\Api\Professional\Store\ToggleProductActiveRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductCommissionRequest;
use App\Http\Requests\Api\Professional\Store\UpdateProductDiscountRequest;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function makeStaffCatalogProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();
    $pro->professional_type = 'brand';

    return $pro;
}

it('delegates /catalog to BrandCatalogController::index with the professional bound', function () {
    $pro = makeStaffCatalogProfessional();

    $delegate = Mockery::mock(BrandCatalogController::class);
    $delegate->shouldReceive('index')
        ->once()
        ->withArgs(function (Request $request) use ($pro) {
            return $request->attributes->get('professional')?->id === $pro->id;
        })
        ->andReturn(new JsonResponse(['products' => []]));

    $controller = new StaffBrandCatalogController($delegate);
    $response = $controller->index(Request::create('/', 'GET'), $pro);

    expect($response->getStatusCode())->toBe(200);
});

it('delegates /catalog/all to BrandCatalogController::all', function () {
    $pro = makeStaffCatalogProfessional();

    $delegate = Mockery::mock(BrandCatalogController::class);
    $delegate->shouldReceive('all')
        ->once()
        ->withArgs(fn (Request $request) => $request->attributes->get('professional')?->id === $pro->id)
        ->andReturn(new JsonResponse(['products' => []]));

    $controller = new StaffBrandCatalogController($delegate);
    $response = $controller->all(Request::create('/', 'GET'), $pro);

    expect($response->getStatusCode())->toBe(200);
});

it('delegates /catalog/debug to BrandCatalogController::debug', function () {
    $pro = makeStaffCatalogProfessional();

    $delegate = Mockery::mock(BrandCatalogController::class);
    $delegate->shouldReceive('debug')
        ->once()
        ->withArgs(fn (Request $request) => $request->attributes->get('professional')?->id === $pro->id)
        ->andReturn(new JsonResponse(['shop' => 'test.myshopify.com']));

    $controller = new StaffBrandCatalogController($delegate);
    $response = $controller->debug(Request::create('/?mode=all', 'GET'), $pro);

    expect($response->getStatusCode())->toBe(200);
});

it('delegates PATCH /commission to BrandCatalogController::updateCommission with professional + gid', function () {
    $pro = makeStaffCatalogProfessional();
    $gid = 'gid://shopify/Product/123';

    $delegate = Mockery::mock(BrandCatalogController::class);
    $delegate->shouldReceive('updateCommission')
        ->once()
        ->withArgs(function (UpdateProductCommissionRequest $request, string $productGid) use ($pro, $gid) {
            return $request->attributes->get('professional')?->id === $pro->id
                && $productGid === $gid;
        })
        ->andReturn(new JsonResponse(['commission_override' => 12.5]));

    $controller = new StaffBrandCatalogController($delegate);
    $formRequest = UpdateProductCommissionRequest::create('/', 'PATCH', ['commission_override' => 12.5]);
    $response = $controller->updateCommission($formRequest, $pro, $gid);

    expect($response->getStatusCode())->toBe(200);
});

it('delegates PATCH /discount to BrandCatalogController::updateDiscount with professional + gid', function () {
    $pro = makeStaffCatalogProfessional();
    $gid = 'gid://shopify/Product/456';

    $delegate = Mockery::mock(BrandCatalogController::class);
    $delegate->shouldReceive('updateDiscount')
        ->once()
        ->withArgs(function (UpdateProductDiscountRequest $request, string $productGid) use ($pro, $gid) {
            return $request->attributes->get('professional')?->id === $pro->id
                && $productGid === $gid;
        })
        ->andReturn(new JsonResponse(['affiliate_discount_pct' => 10]));

    $controller = new StaffBrandCatalogController($delegate);
    $formRequest = UpdateProductDiscountRequest::create('/', 'PATCH', ['affiliate_discount_pct' => 10]);
    $response = $controller->updateDiscount($formRequest, $pro, $gid);

    expect($response->getStatusCode())->toBe(200);
});

it('delegates PATCH /active to BrandCatalogController::toggleActive with professional + gid', function () {
    $pro = makeStaffCatalogProfessional();
    $gid = 'gid://shopify/Product/789';

    $delegate = Mockery::mock(BrandCatalogController::class);
    $delegate->shouldReceive('toggleActive')
        ->once()
        ->withArgs(function (ToggleProductActiveRequest $request, string $productGid) use ($pro, $gid) {
            return $request->attributes->get('professional')?->id === $pro->id
                && $productGid === $gid;
        })
        ->andReturn(new JsonResponse(['active' => false]));

    $controller = new StaffBrandCatalogController($delegate);
    $formRequest = ToggleProductActiveRequest::create('/', 'PATCH', ['active' => false]);
    $response = $controller->toggleActive($formRequest, $pro, $gid);

    expect($response->getStatusCode())->toBe(200);
});
