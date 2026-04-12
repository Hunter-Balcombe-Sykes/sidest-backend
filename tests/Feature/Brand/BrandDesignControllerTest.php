<?php

use App\Http\Controllers\Api\Professional\Store\BrandDesignController;
use App\Http\Requests\Api\Professional\Store\UpdateBrandDesignOverridesRequest;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function makeBrandDesignRequest(string $method = 'GET', array $params = [], ?string $type = 'brand'): Request
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

it('returns 403 when non-brand tries to view design', function () {
    $controller = new BrandDesignController();
    $response = $controller->show(makeBrandDesignRequest('GET', [], 'influencer'));

    expect($response->status())->toBe(403);
    expect($response->getData(true)['message'])->toContain('brand accounts');
});

it('returns 403 when non-brand tries to resync design', function () {
    $controller = new BrandDesignController();
    $response = $controller->resync(makeBrandDesignRequest('POST', [], 'influencer'));

    expect($response->status())->toBe(403);
});

it('returns 403 when non-brand tries to update overrides', function () {
    $controller = new BrandDesignController();

    $request = makeBrandDesignRequest('PATCH', ['primary_color' => '#ff0000'], 'influencer');
    $formRequest = UpdateBrandDesignOverridesRequest::createFrom($request);
    $formRequest->attributes->set('professional', $request->attributes->get('professional'));

    $response = $controller->updateOverrides($formRequest);

    expect($response->status())->toBe(403);
});

it('returns 422 when DELETE override token is not in allowlist', function () {
    $controller = new BrandDesignController();
    $response = $controller->resetOverride(makeBrandDesignRequest('DELETE'), 'not_a_real_token');

    expect($response->status())->toBe(422);
    expect($response->getData(true)['message'])->toContain('Unknown design token');
});

it('validates primary_color as hex in UpdateBrandDesignOverridesRequest', function () {
    $request = new UpdateBrandDesignOverridesRequest();
    $rules = $request->rules();

    expect($rules)->toHaveKey('primary_color');
    expect($rules['primary_color'])->toContain('sometimes');
    expect($rules['primary_color'])->toContain('nullable');

    // The hex regex rule is an element of the rules array
    $hasHexRule = false;
    foreach ($rules['primary_color'] as $rule) {
        if (is_string($rule) && str_starts_with($rule, 'regex:') && str_contains($rule, '#')) {
            $hasHexRule = true;
            break;
        }
    }
    expect($hasHexRule)->toBeTrue();
});

it('validates all 10 design tokens are allowed in override request', function () {
    $request = new UpdateBrandDesignOverridesRequest();
    $rules = $request->rules();

    $expectedKeys = [
        'primary_color',
        'secondary_color',
        'background_color',
        'text_color',
        'border_radius',
        'border_width',
        'button_background',
        'button_text_color',
        'heading_font',
        'body_font',
    ];

    foreach ($expectedKeys as $key) {
        expect($rules)->toHaveKey($key);
    }
});

it('validates font family rejects HTML-like input', function () {
    $request = new UpdateBrandDesignOverridesRequest();
    $rules = $request->rules();

    $hasRegex = false;
    foreach ($rules['heading_font'] as $rule) {
        if (is_string($rule) && str_starts_with($rule, 'regex:')) {
            $hasRegex = true;
            break;
        }
    }
    expect($hasRegex)->toBeTrue();
});
