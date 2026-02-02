<?php

namespace App\Http\Middleware;

use App\Models\Core\Professional\Professional;
use App\Services\Billing\Entitlements;
use Closure;
use Illuminate\Http\Request;

class RequirePlan
{
    public function __construct(private Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $minPlanKey)
    {
        /** @var Professional $professional */
        $professional = $request->user()?->professional ?? null; // adapt to YOUR auth setup

        if (!$professional) {
            return response()->json(['message' => 'Professional not found'], 404);
        }

        if (!$this->entitlements->hasPlan($professional, $minPlanKey)) {
            return response()->json([
                'message' => 'Upgrade required',
                'required_plan' => $minPlanKey,
            ], 403);
        }

        return $next($request);
    }
}
