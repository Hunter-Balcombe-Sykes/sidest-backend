<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
|--------------------------------------------------------------------------
| Horizon Dashboard Auth Gate
|--------------------------------------------------------------------------
| Behavior matrix for AppServiceProvider::authorizeHorizonRequest:
|
|   env       creds set    auth header                 result
|   --------  -----------  --------------------------  -------
|   non-prod  any          any                         allow
|   prod      no           any                         deny (403 via Horizon)
|   prod      yes          missing                     401 + WWW-Authenticate
|   prod      yes          wrong                       401 + WWW-Authenticate
|   prod      yes          correct                     allow
|
| Why a static method instead of an inline closure: the closure needs to
| run inside Horizon's auth middleware which we can't reach from a test
| without a configured Horizon route. Extracting the gate makes it a pure
| function over (Request, env, config) and trivially testable.
*/

beforeEach(function () {
    config([
        'horizon.dashboard.username' => null,
        'horizon.dashboard.password' => null,
    ]);
});

afterEach(function () {
    // Reset env so subsequent tests see the default 'testing' environment.
    $this->app->detectEnvironment(fn () => 'testing');
});

it('allows access in non-production environments', function () {
    $request = Request::create('/horizon');

    expect(AppServiceProvider::authorizeHorizonRequest($request))->toBeTrue();
});

it('denies access in production when credentials are not configured', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $request = Request::create('/horizon');

    expect(AppServiceProvider::authorizeHorizonRequest($request))->toBeFalse();
});

it('denies access in production when only username is configured', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['horizon.dashboard.username' => 'admin']);

    $request = Request::create('/horizon');

    expect(AppServiceProvider::authorizeHorizonRequest($request))->toBeFalse();
});

it('denies access in production when only password is configured', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['horizon.dashboard.password' => 'secret-pass']);

    $request = Request::create('/horizon');

    expect(AppServiceProvider::authorizeHorizonRequest($request))->toBeFalse();
});

it('challenges with 401 in production when basic auth is missing', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config([
        'horizon.dashboard.username' => 'admin',
        'horizon.dashboard.password' => 'secret-pass',
    ]);

    $request = Request::create('/horizon');

    try {
        AppServiceProvider::authorizeHorizonRequest($request);
        $this->fail('Expected HttpException but none was thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(401);
        expect($e->getHeaders())->toHaveKey('WWW-Authenticate');
        expect($e->getHeaders()['WWW-Authenticate'])->toBe('Basic realm="Horizon"');
    }
});

it('challenges with 401 in production when basic auth password is wrong', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config([
        'horizon.dashboard.username' => 'admin',
        'horizon.dashboard.password' => 'secret-pass',
    ]);

    $request = Request::create('/horizon', 'GET', [], [], [], [
        'PHP_AUTH_USER' => 'admin',
        'PHP_AUTH_PW' => 'wrong-pass',
    ]);

    try {
        AppServiceProvider::authorizeHorizonRequest($request);
        $this->fail('Expected HttpException but none was thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(401);
    }
});

it('challenges with 401 in production when basic auth username is wrong', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config([
        'horizon.dashboard.username' => 'admin',
        'horizon.dashboard.password' => 'secret-pass',
    ]);

    $request = Request::create('/horizon', 'GET', [], [], [], [
        'PHP_AUTH_USER' => 'someone-else',
        'PHP_AUTH_PW' => 'secret-pass',
    ]);

    try {
        AppServiceProvider::authorizeHorizonRequest($request);
        $this->fail('Expected HttpException but none was thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(401);
    }
});

it('allows access in production with valid basic auth credentials', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config([
        'horizon.dashboard.username' => 'admin',
        'horizon.dashboard.password' => 'secret-pass',
    ]);

    $request = Request::create('/horizon', 'GET', [], [], [], [
        'PHP_AUTH_USER' => 'admin',
        'PHP_AUTH_PW' => 'secret-pass',
    ]);

    expect(AppServiceProvider::authorizeHorizonRequest($request))->toBeTrue();
});

it('uses constant-time comparison resistant to length-mismatch leaks', function () {
    // Smoke check: both very-short and same-length-but-different inputs are
    // rejected. hash_equals returns false in both cases without short-circuiting.
    $this->app->detectEnvironment(fn () => 'production');
    config([
        'horizon.dashboard.username' => 'admin',
        'horizon.dashboard.password' => 'a-long-password-string',
    ]);

    foreach (['x', 'a-long-password-strinX'] as $candidate) {
        $request = Request::create('/horizon', 'GET', [], [], [], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => $candidate,
        ]);

        try {
            AppServiceProvider::authorizeHorizonRequest($request);
            $this->fail("Expected HttpException for candidate {$candidate}.");
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(401);
        }
    }
});
