<?php

use App\Http\Middleware\SecureHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/*
|--------------------------------------------------------------------------
| SecureHeaders middleware
|--------------------------------------------------------------------------
| Pins CSP behavior. The public API surface must stay on `default-src 'none'`
| (no inline anything, no third parties). Horizon's dashboard needs a relaxed
| policy because its Vue SPA is inlined into the layout via Horizon::css/js —
| if `default-src 'none'` reaches Horizon, the dashboard renders as un-styled
| SVG blobs with no Vue app. Trust there is held by the auth gate, not CSP.
*/

function runSecureHeaders(string $path): Response
{
    $middleware = new SecureHeaders;
    $request = Request::create($path);
    $next = fn () => new Response('ok');

    return $middleware->handle($request, $next);
}

it('locks down CSP to default-src none on non-horizon paths', function () {
    $response = runSecureHeaders('/api/health');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toBe("default-src 'none'; frame-ancestors 'none'");
});

it('relaxes CSP for the horizon root path', function () {
    $csp = runSecureHeaders('/horizon')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("'unsafe-inline'")
        ->toContain('fonts.bunny.net')
        ->toContain('data:')
        ->toContain("frame-ancestors 'none'");
});

it('relaxes CSP for horizon sub-paths', function () {
    $csp = runSecureHeaders('/horizon/dashboard')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("style-src 'self' 'unsafe-inline' https://fonts.bunny.net");
});

it('keeps the other security headers identical on both paths', function () {
    foreach (['/api/health', '/horizon', '/horizon/jobs/pending'] as $path) {
        $headers = runSecureHeaders($path)->headers;

        expect($headers->get('X-Frame-Options'))->toBe('DENY');
        expect($headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect($headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
        expect($headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=()');
    }
});
