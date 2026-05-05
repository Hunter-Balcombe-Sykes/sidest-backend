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

// The next two tests exercise the DB lookup path (no pre-set sidest_staff attribute).
// They verify the middleware is fail-closed: a valid Supabase UID alone is not enough —
// a matching SidestStaff DB record must also exist.

it('returns 403 when supabase uid has no matching SidestStaff record in DB', function () {
    setupSidestStaffTable();

    $middleware = new EnsureSidestStaff;
    $request = Request::create('/', 'GET');
    $request->attributes->set('supabase_uid', 'uid-with-no-staff-record');

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    $data = json_decode($response->getContent(), true);
    expect($data['message'])->toBe('Staff access required');
});

it('passes through when supabase uid maps to a SidestStaff record in DB', function () {
    setupSidestStaffTable();

    $uid = 'uid-with-staff-record';
    $now = now()->toDateTimeString();

    \Illuminate\Support\Facades\DB::connection('pgsql')->table('core.sidest_staff')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'auth_user_id' => $uid,
        'role' => SidestStaff::ROLE_SUPPORT,
        'primary_email' => 'staff@example.test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $middleware = new EnsureSidestStaff;
    $request = Request::create('/', 'GET');
    $request->attributes->set('supabase_uid', $uid);

    $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});
