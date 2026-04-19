<?php

use App\Http\Controllers\Api\Professional\BrandAffiliateController;
use App\Http\Controllers\Api\Professional\BrandPartnerController;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\BrandPartnerSiteSettingsSync;
use Illuminate\Http\Request;

function brandGuardRequest(string $professionalType): Request
{
    $request = Request::create('/api/test', 'GET');
    $request->attributes->set('professional', new Professional([
        'professional_type' => $professionalType,
    ]));

    return $request;
}

it('blocks non-brand users from brand affiliate listing endpoint', function () {
    $controller = new BrandAffiliateController;
    $request = brandGuardRequest('barber');

    expect($controller->index($request)->status())->toBe(403);
});

it('blocks brand users from managing their own partner list endpoints', function () {
    $controller = new BrandPartnerController;
    $request = brandGuardRequest('brand');
    $brandPartnerLinks = \Mockery::mock(BrandPartnerLinkService::class);
    $sync = \Mockery::mock(BrandPartnerSiteSettingsSync::class);
    $lifecycle = \Mockery::mock(BrandPartnerLinkLifecycleService::class);

    expect($controller->promote($request, '00000000-0000-0000-0000-000000000000', $brandPartnerLinks, $sync)->status())->toBe(403);
    expect($controller->disconnect($request, '00000000-0000-0000-0000-000000000000', $lifecycle)->status())->toBe(403);
});
