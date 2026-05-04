<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Verifies X-Hydrogen-Api-Key header for internal Hydrogen server-to-server endpoints.
class VerifyHydrogenApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.hydrogen.api_key');

        if ($expected === '') {
            // In local dev and CI, allow unauthenticated hydrogen requests so
            // developers don't need to provision a shared secret on every machine.
            // In production/staging, fail closed — a missing key means the env
            // is misconfigured and hydrogen endpoints must not be reachable.
            if (app()->isLocal() || app()->runningUnitTests()) {
                return $next($request);
            }

            throw new \RuntimeException('HYDROGEN_API_KEY must be set in production.');
        }

        $provided = (string) $request->header('X-Hydrogen-Api-Key', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid or missing API key.'], 403);
        }

        return $next($request);
    }
}
