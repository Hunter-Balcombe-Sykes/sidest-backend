<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddPublicCacheHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never cache authenticated API responses.
        if ($request->headers->has('Authorization')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $this->mergeVary($response, ['Authorization', 'Cookie', 'Accept-Encoding']);
            return $response;
        }

        // Only cache successful GET requests to public routes
        if (
            $request->isMethod('GET') &&
            $response->isSuccessful() &&
            str_starts_with($request->path(), 'api/public/')
        ) {
            $response->headers->set('Cache-Control', 'public, max-age=900, s-maxage=900'); // 15 min
            $this->mergeVary($response, ['Accept-Encoding']);
            if (! $response->headers->has('X-Cache-Status')) {
                $response->headers->set('X-Cache-Status', 'MISS');
            }
        }

        return $response;
    }

    private function mergeVary(Response $response, array $tokens): void
    {
        $existing = (string) $response->headers->get('Vary', '');
        $varyMap = [];

        if ($existing !== '') {
            foreach (explode(',', $existing) as $token) {
                $trimmed = trim($token);
                if ($trimmed !== '') {
                    $varyMap[strtolower($trimmed)] = $trimmed;
                }
            }
        }

        foreach ($tokens as $token) {
            $trimmed = trim($token);
            if ($trimmed !== '') {
                $varyMap[strtolower($trimmed)] = $trimmed;
            }
        }

        if ($varyMap !== []) {
            $response->headers->set('Vary', implode(', ', array_values($varyMap)));
        }
    }
}
