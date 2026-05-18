<?php

use App\Http\Middleware\Auth\RequireEmailVerified;
use Illuminate\Http\Request;

it('returns 401 with structured error when supabase claims are missing', function () {
    $middleware = new RequireEmailVerified;
    $request = Request::create('/x', 'GET');

    $response = $middleware->handle($request, fn () => abort(500, 'next should not run'));

    expect($response->status())->toBe(401);
    expect($response->getData(true))->toMatchArray([
        'error' => 'unauthenticated',
    ]);
});

it('returns 403 with email_verification_required error when email_verified is false', function () {
    $middleware = new RequireEmailVerified;
    $request = Request::create('/x', 'GET');
    $request->attributes->set('supabase_claims', [
        'email' => 'unverified@example.com',
        'email_verified' => false,
    ]);

    $response = $middleware->handle($request, fn () => abort(500, 'next should not run'));

    expect($response->status())->toBe(403);
    expect($response->getData(true))->toMatchArray([
        'error' => 'email_verification_required',
        'email' => 'unverified@example.com',
    ]);
});

it('returns 403 when email_verified claim is absent (treated as false)', function () {
    $middleware = new RequireEmailVerified;
    $request = Request::create('/x', 'GET');
    $request->attributes->set('supabase_claims', ['email' => 'no-claim@example.com']);

    $response = $middleware->handle($request, fn () => abort(500, 'next should not run'));

    expect($response->status())->toBe(403);
    expect($response->getData(true)['error'])->toBe('email_verification_required');
});

it('passes through when email_verified is true at the root', function () {
    $middleware = new RequireEmailVerified;
    $request = Request::create('/x', 'GET');
    $request->attributes->set('supabase_claims', [
        'email' => 'verified@example.com',
        'email_verified' => true,
    ]);

    $called = false;
    $response = $middleware->handle($request, function () use (&$called) {
        $called = true;

        return response('ok', 200);
    });

    expect($called)->toBeTrue();
    expect($response->status())->toBe(200);
});

it('passes through when email_verified lives in user_metadata (legacy Supabase JWT shape)', function () {
    // This is the real-world shape: Supabase puts email_verified inside
    // user_metadata for email/password sign-ups on legacy projects. The
    // root-level claim is absent, so the previous version of this
    // middleware mistakenly 403'd already-verified users.
    $middleware = new RequireEmailVerified;
    $request = Request::create('/x', 'GET');
    $request->attributes->set('supabase_claims', [
        'email' => 'verified@example.com',
        'user_metadata' => [
            'email_verified' => true,
            'sub' => 'abc-123',
        ],
    ]);

    $called = false;
    $response = $middleware->handle($request, function () use (&$called) {
        $called = true;

        return response('ok', 200);
    });

    expect($called)->toBeTrue();
    expect($response->status())->toBe(200);
});

it('still 403s when both root and user_metadata email_verified are false', function () {
    $middleware = new RequireEmailVerified;
    $request = Request::create('/x', 'GET');
    $request->attributes->set('supabase_claims', [
        'email' => 'unverified@example.com',
        'email_verified' => false,
        'user_metadata' => ['email_verified' => false],
    ]);

    $response = $middleware->handle($request, fn () => abort(500, 'next should not run'));

    expect($response->status())->toBe(403);
    expect($response->getData(true)['error'])->toBe('email_verification_required');
});
