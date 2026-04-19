<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffInviteController;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns paginated invites for a brand', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();
    $mockQuery->shouldReceive('paginate')->andReturn(
        new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1)
    );

    DB::shouldReceive('table')->with('brand.brand_affiliate_invites')->andReturn($mockQuery);

    $controller = new StaffInviteController();
    $response = $controller->index(Request::create('/', 'GET'), $professional);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKeys(['data', 'meta']);
});

it('filters invites by status when provided', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $mockQuery = Mockery::mock();
    $mockQuery->shouldReceive('where')->andReturnSelf();
    $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();
    $mockQuery->shouldReceive('paginate')->andReturn(
        new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1)
    );

    DB::shouldReceive('table')->with('brand.brand_affiliate_invites')->andReturn($mockQuery);

    $controller = new StaffInviteController();
    $response = $controller->index(Request::create('/', 'GET', ['status' => 'pending']), $professional);

    expect($response->status())->toBe(200);
});

it('expires a pending invite', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $invite = Mockery::mock(BrandAffiliateInvite::class)->makePartial();
    $invite->id = (string) Str::uuid();
    $invite->status = 'pending';
    $invite->shouldReceive('save')->once();

    $controller = new StaffInviteController();
    $response = $controller->cancel(Request::create('/', 'DELETE'), $professional, $invite);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['status'])->toBe('expired');
});

it('returns 422 when trying to cancel an accepted invite', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);

    $invite = new BrandAffiliateInvite(['id' => (string) Str::uuid(), 'status' => 'accepted']);

    $controller = new StaffInviteController();
    $response = $controller->cancel(Request::create('/', 'DELETE'), $professional, $invite);

    expect($response->status())->toBe(422);
});

it('returns success immediately for an already-expired invite', function () {
    $professional = new Professional(['id' => (string) Str::uuid()]);
    $inviteId = (string) Str::uuid();

    $invite = new BrandAffiliateInvite(['id' => $inviteId, 'status' => 'expired']);

    $controller = new StaffInviteController();
    $response = $controller->cancel(Request::create('/', 'DELETE'), $professional, $invite);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['status'])->toBe('expired');
});
