<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Route;

// Verifies that AuthorizationException from policy denials (denyAsNotFound,
// denyWithStatus) is converted to the correct JSON response by the exception
// handler. The policy enforcement tests call controllers directly and catch
// the raw exception — this test exercises the full HTTP → handler → response
// path that production traffic actually uses.

beforeEach(function () {
    // Register ephemeral test routes that throw AuthorizationException with
    // the exact shapes our policies produce (via Response::authorize(), which
    // is what Gate calls internally). Isolated to 'api/test/*' so they don't
    // collide with real routes.
    Route::middleware('api')->group(function () {
        Route::get('api/test/policy-deny-404', function () {
            // Mirrors: policy returns Response::denyAsNotFound() → Gate::authorize() throws
            Response::denyAsNotFound('Not found.')->authorize();
        });

        Route::get('api/test/policy-deny-423', function () {
            // Mirrors: policy returns Response::denyWithStatus(423) → Gate::authorize() throws
            Response::denyWithStatus(423, 'Account is pending deletion.')->authorize();
        });

        Route::get('api/test/policy-deny-403', function () {
            // Plain false from a policy produces AccessDeniedHttpException; this
            // tests a raw AuthorizationException with no status (the fallback path).
            throw new AuthorizationException('Access denied.');
        });
    });
});

it('converts a denyAsNotFound policy denial to a 404 JSON response', function () {
    $response = $this->getJson('/api/test/policy-deny-404');

    expect($response->status())->toBe(404);
    expect($response->json('message'))->toBe('Resource not found');
});

it('converts a denyWithStatus(423) policy denial to a 423 JSON response', function () {
    $response = $this->getJson('/api/test/policy-deny-423');

    expect($response->status())->toBe(423);
    expect($response->json('message'))->toBe('Account is pending deletion.');
});

it('converts a bare AuthorizationException (no response) to a 403 JSON response', function () {
    $response = $this->getJson('/api/test/policy-deny-403');

    expect($response->status())->toBe(403);
    expect($response->json('message'))->toBe('Access denied.');
});
