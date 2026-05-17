<?php

use App\Http\Controllers\Api\Professional\Store\BrandCatalogController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandCatalogController;
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
