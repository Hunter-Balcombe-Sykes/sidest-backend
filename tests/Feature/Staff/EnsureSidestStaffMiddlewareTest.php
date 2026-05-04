<?php

use App\Http\Middleware\Auth\EnsureSidestStaff;
use App\Models\Core\Staff\SidestStaff;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Helper: build a request with a pre-resolved staff record (bypasses DB lookup).
function makeStaffRequest(SidestStaff $staff): Request
{
    $request = Request::create('/', 'GET');
    $request->attributes->set('supabase_uid', 'test-uid');
    $request->attributes->set('sidest_staff', $staff);

    return $request;
}

function makeStaffInstance(string $role): SidestStaff
{
    $staff = new SidestStaff;
    $staff->role = $role;

    return $staff;
}

it('passes through when no role restriction is set', function () {
    $middleware = new EnsureSidestStaff;
    $request = makeStaffRequest(makeStaffInstance(SidestStaff::ROLE_SUPPORT));

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

it('passes through when staff matches the required role', function () {
    $middleware = new EnsureSidestStaff;
    $request = makeStaffRequest(makeStaffInstance(SidestStaff::ROLE_ADMIN));

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), SidestStaff::ROLE_ADMIN);

    expect($response->getStatusCode())->toBe(200);
});

it('returns 403 when staff role does not match the required role', function () {
    $middleware = new EnsureSidestStaff;
    $request = makeStaffRequest(makeStaffInstance(SidestStaff::ROLE_SUPPORT));

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]), SidestStaff::ROLE_ADMIN);

    expect($response->getStatusCode())->toBe(403);
    $data = json_decode($response->getContent(), true);
    expect($data['message'])->toBe('Insufficient staff role');
});

it('returns 401 when no supabase uid is present', function () {
    $middleware = new EnsureSidestStaff;
    $request = Request::create('/', 'GET');

    $response = $middleware->handle($request, fn () => new Response('ok'));

    expect($response->getStatusCode())->toBe(401);
});

it('accepts multiple allowed roles (any match passes)', function () {
    $middleware = new EnsureSidestStaff;
    $request = makeStaffRequest(makeStaffInstance(SidestStaff::ROLE_SUPPORT));

    $response = $middleware->handle(
        $request,
        fn () => response()->json(['ok' => true]),
        SidestStaff::ROLE_SUPPORT,
        SidestStaff::ROLE_ADMIN
    );

    expect($response->getStatusCode())->toBe(200);
});
