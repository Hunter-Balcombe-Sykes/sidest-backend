<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionVoidController;
use App\Models\Commerce\Order;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Phase 4+: the staff void controller now operates on commerce.orders rows
// (the "commission" route param resolves to an Order). The service call is
// voidOrder(Order, reason).

it('voids an approved commission order', function () {
    $order = (new Order)->forceFill([
        'id' => (string) Str::uuid(),
        'status' => 'approved',
        'payout_id' => null,
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $voidService->shouldReceive('voidOrder')
        ->once()
        ->with($order, 'staff_manual: duplicate order')
        ->andReturn(true);

    $controller = new StaffCommissionVoidController($voidService);
    $request = Request::create('/', 'POST', ['reason' => 'duplicate order']);

    $response = $controller->void($request, $order);
    $data = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['voided'])->toBeTrue()
        ->and($data['id'])->toBe($order->id);
});

it('returns 422 when order is not approved', function () {
    $order = (new Order)->forceFill([
        'id' => (string) Str::uuid(),
        'status' => 'voided',
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);

    $controller = new StaffCommissionVoidController($voidService);
    $request = Request::create('/', 'POST', ['reason' => 'test']);

    $response = $controller->void($request, $order);

    expect($response->status())->toBe(422);
});

it('returns 422 when order already has a payout', function () {
    $order = (new Order)->forceFill([
        'id' => (string) Str::uuid(),
        'status' => 'approved',
        'payout_id' => (string) Str::uuid(),
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);

    $controller = new StaffCommissionVoidController($voidService);
    $request = Request::create('/', 'POST', ['reason' => 'test']);

    $response = $controller->void($request, $order);

    expect($response->status())->toBe(422);
});

it('returns 409 when optimistic lock loses the race', function () {
    $order = (new Order)->forceFill([
        'id' => (string) Str::uuid(),
        'status' => 'approved',
        'payout_id' => null,
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $voidService->shouldReceive('voidOrder')->andReturn(false);

    $controller = new StaffCommissionVoidController($voidService);
    $request = Request::create('/', 'POST', ['reason' => 'test']);

    $response = $controller->void($request, $order);

    expect($response->status())->toBe(409);
});

it('requires a reason', function () {
    $order = (new Order)->forceFill([
        'id' => (string) Str::uuid(),
        'status' => 'approved',
        'payout_id' => null,
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $controller = new StaffCommissionVoidController($voidService);
    $request = Request::create('/', 'POST', []);

    expect(fn () => $controller->void($request, $order))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
