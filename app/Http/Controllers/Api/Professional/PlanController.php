<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Models\Billing\Plan;

class PlanController extends ApiController
{
    /**
     * List all active plans
     */
    public function index()
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_cents' => $plan->price_cents,
                'currency_code' => $plan->currency_code,
                'billing_interval' => $plan->billing_interval,
                'entitlements' => $plan->entitlements,
            ]);

        return response()->json([
            'data' => $plans,
        ]);
    }
}
