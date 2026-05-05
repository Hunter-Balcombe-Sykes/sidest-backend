<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandProfileController;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns 404 when brand has no brand profile', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);
    $professional->setRelation('brandProfile', null);

    $controller = new StaffBrandProfileController;
    $request = Request::create('/', 'PATCH', ['brand_status' => 'active']);

    $response = $controller->update($request, $professional);

    expect($response->status())->toBe(404);
});

it('updates allowed brand profile fields', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $profile = Mockery::mock(BrandProfile::class)->makePartial();
    $profile->professional_id = $professional->id;
    $profile->brand_status = 'pending';
    $profile->affiliate_visibility = 'invite_only';
    $profile->setup_complete = false;
    $profile->legal_business_name = null;
    $profile->abn = null;
    $profile->acn = null;
    $profile->business_website = null;
    $profile->shouldReceive('save')->once();

    $professional->setRelation('brandProfile', $profile);

    $controller = new StaffBrandProfileController;
    $request = Request::create('/', 'PATCH', [
        'brand_status' => 'storefront_live',
        'affiliate_visibility' => 'public',
        'setup_complete' => true,
        'legal_business_name' => 'Cuts & Co Pty Ltd',
    ]);

    $response = $controller->update($request, $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKey('brand_profile')
        ->and($data['brand_profile'])->toHaveKeys([
            'id', 'brand_status', 'affiliate_visibility', 'setup_complete',
            'legal_business_name', 'abn', 'acn', 'business_website',
        ]);
});

it('rejects unknown brand_status values', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $profile = new BrandProfile(['professional_id' => $professional->id, 'brand_status' => 'active']);
    $professional->setRelation('brandProfile', $profile);

    $controller = new StaffBrandProfileController;
    $request = Request::create('/', 'PATCH', ['brand_status' => 'hacked']);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects invalid business_website', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $profile = new BrandProfile(['professional_id' => $professional->id]);
    $professional->setRelation('brandProfile', $profile);

    $controller = new StaffBrandProfileController;
    $request = Request::create('/', 'PATCH', ['business_website' => 'not-a-url']);

    expect(fn () => $controller->update($request, $professional))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
