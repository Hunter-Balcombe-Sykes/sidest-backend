<?php

use App\Http\Controllers\Api\Professional\Store\AffiliateProductController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAffiliateSelectionController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function makeStaffSelectionProfessional(): Professional
{
    $pro = new Professional;
    $pro->id = (string) Str::uuid();
    $pro->professional_type = 'influencer';

    return $pro;
}

it('delegates to AffiliateProductController::index with the affiliate professional injected', function () {
    $pro = makeStaffSelectionProfessional();

    $delegate = Mockery::mock(AffiliateProductController::class);
    $delegate->shouldReceive('index')
        ->once()
        ->withArgs(function (Request $request) use ($pro) {
            return $request->attributes->get('professional')?->id === $pro->id;
        })
        ->andReturn(new JsonResponse([
            'products' => [],
            'brand_professional_id' => null,
            'default_commission_rate' => 15,
            'custom_photos_enabled' => false,
            'product_image_ratio' => null,
        ]));

    $controller = new StaffAffiliateSelectionController($delegate);
    $response = $controller->index(Request::create('/', 'GET'), $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body)->toHaveKeys(['products', 'brand_professional_id', 'default_commission_rate', 'custom_photos_enabled']);
});
