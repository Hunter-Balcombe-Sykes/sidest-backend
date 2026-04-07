<?php

namespace App\Http\Middleware;

use App\Models\Core\Professional\Professional;
use App\Services\Billing\Entitlements;
use Closure;
use Illuminate\Http\Request;

// V2: Subscription tier gate. Checks professional's plan meets minimum required tier via Entitlements service.
class RequirePlan
{
    public function __construct(private Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $minPlanKey)
    {
        /** @var Professional|null $professional */
        $professional = $request->attributes->get('professional');

        if (! $professional instanceof Professional) {
            return response()->json(['message' => 'Professional not found'], 404);
        }

        if (! $this->entitlements->hasPlan($professional, $minPlanKey)) {
            return response()->json([
                'message' => 'Upgrade required',
                'required_plan' => $minPlanKey,
            ], 403);
        }

        return $next($request);
    }
}
