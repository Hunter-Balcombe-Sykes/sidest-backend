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

        // Only cache successful GET requests to public routes
        if (
            $request->isMethod('GET') &&
            $response->isSuccessful() &&
            str_starts_with($request->path(), 'api/public/')
        ) {
            $response->headers->set('Cache-Control', 'public, max-age=900, s-maxage=900'); // 15 min
            $response->headers->set('Vary', 'Accept-Encoding');
            if (! $response->headers->has('X-Cache-Status')) {
                $response->headers->set('X-Cache-Status', 'MISS');
            }
        }

        return $response;
    }
}
