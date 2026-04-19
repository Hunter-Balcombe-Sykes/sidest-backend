<?php

use App\Http\Controllers\Api\Professional\Store\BrandStoreSettingsController;
use App\Http\Requests\Api\Professional\Store\UpdateBrandStoreSettingsRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function makeBrandSettingsRequest(string $method = 'GET', array $params = [], ?string $type = 'brand'): Request
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

it('returns 403 when non-brand tries to view store settings', function () {
    $service = app(BrandCatalogService::class);
    $controller = new BrandStoreSettingsController($service);

    $response = $controller->show(makeBrandSettingsRequest('GET', [], 'influencer'));

    expect($response->status())->toBe(403);
    expect($response->getData(true)['message'])->toContain('brand accounts');
});

it('returns 403 when non-brand tries to update store settings', function () {
    $service = app(BrandCatalogService::class);
    $controller = new BrandStoreSettingsController($service);

    $request = makeBrandSettingsRequest('PATCH', ['default_commission_rate' => 20], 'influencer');
    $formRequest = UpdateBrandStoreSettingsRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->update($formRequest);

    expect($response->status())->toBe(403);
});

it('validates accent_color format in UpdateBrandStoreSettingsRequest', function () {
    $request = new UpdateBrandStoreSettingsRequest;
    $rules = $request->rules();

    expect($rules['accent_color'])->toContain('sometimes');
    expect($rules['accent_color'])->toContain('nullable');
    expect($rules)->toHaveKey('default_commission_rate');
    expect($rules)->toHaveKey('theme_variant');
    expect($rules)->toHaveKey('product_image_ratio');
});

it('validates product_image_ratio is constrained to valid values', function () {
    $request = new UpdateBrandStoreSettingsRequest;
    $rules = $request->rules();

    expect($rules['product_image_ratio'])->toContain('in:1/1,4/5');
});
