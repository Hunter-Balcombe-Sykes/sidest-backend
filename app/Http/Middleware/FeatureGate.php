<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlags\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;

// V2: Launch-time feature gate. Resolves via FeatureFlagService (DB overrides +
// rollout % + config fallback) and short-circuits with 503 when the flag is off.
// Fails closed: an unknown flag key = off.
// Apply via route middleware: `->middleware('feature:smart_booking')`.
class FeatureGate
{
    public function handle(Request $request, Closure $next, string $flag): mixed
    {
        if (! app(FeatureFlagService::class)->enabled($flag)) {
            return response()->json([
                'message' => 'Feature not available',
                'feature' => $flag,
            ], 503);
        }

        return $next($request);
    }
}
