<?php

use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandCommerceAnalyticsController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function makeStaffAnalyticsProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();
    $pro->professional_type = 'brand';

    return $pro;
}

it('delegates to BrandCommerceAnalyticsController::overview with the route-bound professional injected', function () {
    $pro = makeStaffAnalyticsProfessional();

    $delegate = Mockery::mock(BrandCommerceAnalyticsController::class);
    $delegate->shouldReceive('overview')
        ->once()
        ->withArgs(function (Request $request) use ($pro) {
            $attribute = $request->attributes->get('professional');

            return $attribute instanceof Professional && $attribute->id === $pro->id;
        })
        ->andReturn(new JsonResponse(['delegated' => true]));

    $controller = new StaffBrandCommerceAnalyticsController($delegate);
    $response = $controller->overview(Request::create('/', 'GET'), $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['delegated'])->toBeTrue();
});

it('forwards the original request shape (no mutation of query params)', function () {
    $pro = makeStaffAnalyticsProfessional();

    $delegate = Mockery::mock(BrandCommerceAnalyticsController::class);
    $delegate->shouldReceive('overview')
        ->once()
        ->withArgs(function (Request $request) {
            return $request->query('from') === '2026-04-01' && $request->query('to') === '2026-05-01';
        })
        ->andReturn(new JsonResponse(['ok' => true]));

    $controller = new StaffBrandCommerceAnalyticsController($delegate);
    $request = Request::create('/?from=2026-04-01&to=2026-05-01', 'GET');
    $controller->overview($request, $pro);
});
