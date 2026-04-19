<?php

use App\Http\Controllers\Api\Staff\ProfessionalSiteManagement\StaffCommissionVoidController;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Stripe\CommissionVoidService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

it('voids a pending commission entry', function () {
    $entry = new CommissionLedgerEntry([
        'id'        => (string) Str::uuid(),
        'status'    => 'pending',
        'payout_id' => null,
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $voidService->shouldReceive('voidEntry')
        ->once()
        ->with($entry, 'staff_manual: duplicate order')
        ->andReturn(true);

    $controller = new StaffCommissionVoidController($voidService);
    $request    = Request::create('/', 'POST', ['reason' => 'duplicate order']);

    $response = $controller->void($request, $entry);
    $data     = json_decode($response->getContent(), true);

    expect($response->status())->toBe(200)
        ->and($data['voided'])->toBeTrue()
        ->and($data['id'])->toBe($entry->id);
});

it('returns 422 when entry is not pending', function () {
    $entry = new CommissionLedgerEntry([
        'id'     => (string) Str::uuid(),
        'status' => 'approved',
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);

    $controller = new StaffCommissionVoidController($voidService);
    $request    = Request::create('/', 'POST', ['reason' => 'test']);

    $response = $controller->void($request, $entry);

    expect($response->status())->toBe(422);
});

it('returns 422 when entry already has a payout', function () {
    $entry = new CommissionLedgerEntry([
        'id'        => (string) Str::uuid(),
        'status'    => 'pending',
        'payout_id' => (string) Str::uuid(),
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);

    $controller = new StaffCommissionVoidController($voidService);
    $request    = Request::create('/', 'POST', ['reason' => 'test']);

    $response = $controller->void($request, $entry);

    expect($response->status())->toBe(422);
});

it('returns 409 when optimistic lock loses the race', function () {
    $entry = new CommissionLedgerEntry([
        'id'        => (string) Str::uuid(),
        'status'    => 'pending',
        'payout_id' => null,
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $voidService->shouldReceive('voidEntry')->andReturn(false);

    $controller = new StaffCommissionVoidController($voidService);
    $request    = Request::create('/', 'POST', ['reason' => 'test']);

    $response = $controller->void($request, $entry);

    expect($response->status())->toBe(409);
});

it('requires a reason', function () {
    $entry = new CommissionLedgerEntry([
        'id'        => (string) Str::uuid(),
        'status'    => 'pending',
        'payout_id' => null,
    ]);

    $voidService = Mockery::mock(CommissionVoidService::class);
    $controller  = new StaffCommissionVoidController($voidService);
    $request     = Request::create('/', 'POST', []);

    expect(fn () => $controller->void($request, $entry))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
