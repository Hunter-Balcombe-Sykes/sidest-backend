<?php

use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

// Build a minimal test JWT. Uses HS256 so the JWKS path rejects it,
// forcing the Auth-Server fallback. Claims are readable in the payload.
function makeTestJwt(array $claims = []): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

    return $header.'.'.$payload.'.fakesignature';
}

beforeEach(function () {
    config([
        'supabase.url'          => 'https://proj.supabase.co',
        'supabase.anon_key'     => 'anon-key',
        'supabase.jwks_url'     => 'https://proj.supabase.co/.well-known/jwks.json',
        'supabase.jwt_issuer'   => 'https://proj.supabase.co/auth/v1',
        'supabase.jwt_audience' => 'authenticated',
        'supabase.jwks_fail_closed' => false,
    ]);

    // Force JWKS to fail so every test exercises the fallback path.
    $cacheLock = Mockery::mock(CacheLockService::class);
    $cacheLock->shouldReceive('rememberLocked')
        ->andThrow(new RuntimeException('JWKS unavailable'));

    $this->middleware = new VerifySupabaseJwt($cacheLock);
    $this->next = fn ($req) => response()->json(['ok' => true]);
});

// ── UUID validation ──────────────────────────────────────────────────────────

it('rejects auth-server response when user id is not a UUID', function () {
    $jwt = makeTestJwt([
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'not-a-uuid'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

it('rejects auth-server response when user id is an empty string', function () {
    $jwt = makeTestJwt(['iss' => 'https://proj.supabase.co/auth/v1', 'aud' => 'authenticated']);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => ''], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

// ── Issuer validation ────────────────────────────────────────────────────────

it('rejects auth-server path when JWT issuer does not match config', function () {
    $jwt = makeTestJwt([
        'iss' => 'https://other-project.supabase.co/auth/v1',
        'aud' => 'authenticated',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

// ── Audience validation ──────────────────────────────────────────────────────

it('rejects auth-server path when JWT audience does not match config', function () {
    $jwt = makeTestJwt([
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'wrong-audience',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(401);
});

// ── Fail-closed feature flag ─────────────────────────────────────────────────

it('refuses fallback and returns 503 when jwks_fail_closed is true', function () {
    config(['supabase.jwks_fail_closed' => true]);

    $jwt = makeTestJwt(['iss' => 'https://proj.supabase.co/auth/v1', 'aud' => 'authenticated']);

    // Auth-server should never be called
    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(503);
    Http::assertNothingSent();
});

// ── Fallback logging ─────────────────────────────────────────────────────────

it('logs a warning when auth-server fallback is invoked', function () {
    Log::spy();

    $uid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    $jwt = makeTestJwt([
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => $uid], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $this->middleware->handle($request, $this->next);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => str_contains($msg, 'falling back to auth server'))
        ->once();
});

// ── Happy path: fallback succeeds ───────────────────────────────────────────

it('passes request through when auth-server returns valid UUID with correct claims', function () {
    $uid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
    $jwt = makeTestJwt([
        'iss' => 'https://proj.supabase.co/auth/v1',
        'aud' => 'authenticated',
    ]);

    Http::fake([
        'https://proj.supabase.co/auth/v1/user' => Http::response(['id' => $uid], 200),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
    ]);

    $response = $this->middleware->handle($request, $this->next);

    expect($response->getStatusCode())->toBe(200)
        ->and($request->attributes->get('supabase_uid'))->toBe($uid);
});
