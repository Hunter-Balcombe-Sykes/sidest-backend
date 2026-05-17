<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Security headers on all responses (XFO, CSP, HSTS in production, nosniff, referrer-policy, permissions-policy).
class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // CORS: always allow any origin. HandleCors middleware normally adds this
        // but Laravel Cloud's edge proxy can strip it on some responses. Setting it
        // here (global, appended last) guarantees the header survives.
        if (! $response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Horizon ships its dashboard as inline <style> / <script type="module"> blocks
        // (vendor/laravel/horizon/src/Horizon.php::css/js) plus a webfont from fonts.bunny.net.
        // The default `default-src 'none'` policy strips all of them and the dashboard
        // renders as un-styled, oversized SVG blobs. Loosen CSP for Horizon's path only —
        // the admin gate in AppServiceProvider::authorizeHorizonRequest contains the trust.
        if ($request->is('horizon', 'horizon/*')) {
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; "
                ."style-src 'self' 'unsafe-inline' https://fonts.bunny.net; "
                // 'unsafe-eval' is required by Vue's runtime template compiler bundled
                // into Horizon's app.js — without it, mount() throws EvalError on every
                // screen render and the dashboard never appears.
                ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
                ."font-src 'self' https://fonts.bunny.net; "
                .'img-src \'self\' data:; '
                ."connect-src 'self'; "
                ."frame-ancestors 'none'"
            );
        } else {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        if (! app()->environment('local', 'testing')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
