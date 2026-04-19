<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffBrandAffiliateLinkController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Staff\SidestStaff;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\DTO\DisconnectResult;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('returns 200 with void counts on sync path', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);
    $staff = (new SidestStaff)->forceFill(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('disconnect')->once()->andReturn(new DisconnectResult(
        disconnected: true,
        voidedCommissionCount: 5,
        voidedCommissionCents: 7500,
        selectionsRemoved: 3,
        pendingCommissionCount: 5,
        pendingCommissionCents: 7500,
        voidedAsync: false,
    ));

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'DELETE', [
        'reason' => 'migrating off platform per customer request',
        'on_pending_commissions' => 'void',
    ]);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->destroy($request, $brand, $affiliate);
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200);
    expect($payload['data']['voided_commission_count'])->toBe(5);
    expect($payload['data']['voided_commission_cents'])->toBe(7500);
});

it('returns 202 with voided_async:true on async overflow', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);
    $staff = (new SidestStaff)->forceFill(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('disconnect')->once()->andReturn(new DisconnectResult(
        disconnected: true,
        voidedCommissionCount: 0,
        voidedCommissionCents: 0,
        selectionsRemoved: 7,
        pendingCommissionCount: 412,
        pendingCommissionCents: 61800,
        voidedAsync: true,
    ));

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'DELETE', [
        'reason' => 'Brand account closure — affiliate notified via email',
        'on_pending_commissions' => 'void',
    ]);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->destroy($request, $brand, $affiliate);
    $payload = json_decode($response->getContent(), true);

    expect($response->status())->toBe(202);
    expect($payload['data']['voided_async'])->toBeTrue();
    expect($payload['data']['pending_commission_count'])->toBe(412);
});

it('returns 404 when link does not exist', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);
    $staff = (new SidestStaff)->forceFill(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldReceive('disconnect')->once()->andReturn(new DisconnectResult(
        disconnected: false,
        voidedCommissionCount: 0,
        voidedCommissionCents: 0,
        selectionsRemoved: 0,
    ));

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'DELETE', [
        'reason' => 'some valid reason here',
        'on_pending_commissions' => 'keep',
    ]);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->destroy($request, $brand, $affiliate);
    expect($response->status())->toBe(404);
});

it('returns 422 when on_pending_commissions is void but reason is under 20 chars', function () {
    $brand = new Professional(['id' => (string) Str::uuid()]);
    $affiliate = new Professional(['id' => (string) Str::uuid()]);
    $staff = (new SidestStaff)->forceFill(['id' => (string) Str::uuid()]);

    $svc = Mockery::mock(BrandPartnerLinkLifecycleService::class);
    $svc->shouldNotReceive('disconnect');

    $controller = new StaffBrandAffiliateLinkController($svc);
    $request = Request::create('/', 'DELETE', [
        'reason' => 'too short rsn', // 13 chars
        'on_pending_commissions' => 'void',
    ]);
    $request->attributes->set('sidest_staff', $staff);

    $response = $controller->destroy($request, $brand, $affiliate);
    expect($response->status())->toBe(422);
});
