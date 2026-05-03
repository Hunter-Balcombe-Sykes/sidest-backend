<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandAffiliateLinkController;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\SidestStaff;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns 201 with the new link on success', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staff = (new SidestStaff)->forceFill(['id' => (string) Str::uuid()]);

    $link = new BrandPartnerLink([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affiliate->id,
        'brand_professional_id' => $brand->id,
        'slot' => 2,
    ]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('createForStaff')
        ->once()
        ->with(Mockery::type(Professional::class), Mockery::type(Professional::class), 'Lost invite email recovery', $staff->id)
        ->andReturn($link);

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'POST', ['reason' => 'Lost invite email recovery']);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->store($request, $brand, $affiliate);
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(201);
    expect($payload['data']['link']['slot'])->toBe(2);
});

it('returns 422 when reason is too short', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staff = (new SidestStaff)->forceFill(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldNotReceive('createForStaff');

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'POST', ['reason' => 'too short']);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->store($request, $brand, $affiliate);
    expect($response->status())->toBe(422);
});

it('returns 409 when link already exists', function () {
    $brand = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'brand']);
    $affiliate = new Professional(['id' => (string) Str::uuid(), 'professional_type' => 'professional']);
    $staff = (new SidestStaff)->forceFill(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('createForStaff')
        ->andThrow(new RuntimeException('You are already connected to this brand partner.'));

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'POST', ['reason' => 'Manual recovery attempt']);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->store($request, $brand, $affiliate);
    expect($response->status())->toBe(409);
});
