<?php

use App\Http\Requests\Api\Professional\Store\UpdateBrandStoreSettingsRequest;
use App\Models\Core\Professional\Professional;
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

// Non-brand access is now rejected by the `brand.only` middleware (EnsureBrandAccount)
// before the controller is reached — see tests/Unit/Middleware/EnsureBrandAccountTest.php.

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
