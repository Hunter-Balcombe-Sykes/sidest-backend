<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// V2: Launch-time feature gate. Reads `sidest.features.{flag}` and short-circuits
// with 503 when the flag is false. Fails closed: an unknown flag key = off.
// Apply via route middleware: `->middleware('feature:smart_booking')`.
class FeatureGate
{
    public function handle(Request $request, Closure $next, string $flag)
    {
        if (! (bool) config("partna.features.{$flag}", false)) {
            return response()->json([
                'message' => 'Feature not available',
                'feature' => $flag,
            ], 503);
        }

        return $next($request);
    }
}
