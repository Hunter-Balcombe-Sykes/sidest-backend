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

        // Skip validation in dev if no key is configured
        if ($expected === '') {
            return $next($request);
        }

        $provided = (string) $request->header('X-Hydrogen-Api-Key', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid or missing API key.'], 403);
        }

        return $next($request);
    }
}
