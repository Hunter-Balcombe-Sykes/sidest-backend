<?php

use App\Http\Controllers\Api\Professional\Brand\BrandPartnerController;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\Brand\BrandPartnerLinkLifecycleService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use App\Services\Professional\Brand\BrandPartnerSiteSettingsSync;
use Illuminate\Http\Request;

function brandGuardRequest(string $professionalType): Request
{
    $request = Request::create('/api/test', 'GET');
    $request->attributes->set('professional', new Professional([
        'professional_type' => $professionalType,
    ]));

    return $request;
}

// Brand-affiliate role check moved to `brand.only` middleware (audit fix #PH4-3);
// EnsureBrandAccountTest covers the gating. The brand-side controller no longer
// has an inline guard, so a unit test that bypasses middleware is meaningless.

it('blocks brand users from managing their own partner list endpoints', function () {
    $controller = new BrandPartnerController;
    $request = brandGuardRequest('brand');
    $brandPartnerLinks = \Mockery::mock(BrandPartnerLinkService::class);
    $sync = \Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $lifecycle = \Mockery::mock(BrandPartnerLinkLifecycleService::class);

    expect($controller->promote($request, '00000000-0000-0000-0000-000000000000', $brandPartnerLinks, $sync)->status())->toBe(403);
    expect($controller->disconnect($request, '00000000-0000-0000-0000-000000000000', $lifecycle)->status())->toBe(403);
});
