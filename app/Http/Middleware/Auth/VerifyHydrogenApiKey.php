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
            // Dev/test bypass: missing config is acceptable when running locally
            // or under the test suite. Anywhere else (production, staging, any
            // unrecognized env) we fail closed — without this gate, an empty
            // HYDROGEN_API_KEY on a production deploy would silently open every
            // /internal/hydrogen/* route, including the deployment-token endpoint
            // that can rewrite a brand's storefront, to anonymous traffic.
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }

            throw new \RuntimeException(
                'services.hydrogen.api_key is not configured — refusing to fall through to bypass outside local/testing.'
            );
        }

        $provided = (string) $request->header('X-Hydrogen-Api-Key', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid or missing API key.'], 403);
        }

        return $next($request);
    }
}
