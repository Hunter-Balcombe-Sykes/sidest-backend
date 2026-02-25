<?php

namespace App\Http\Controllers\Api\Professional;

use App\Actions\Subscription\CreateProfessionalSubscriptionAction;
use App\Actions\Subscription\ChangeProfessionalPlanAction;
use App\Actions\Subscription\CancelProfessionalSubscriptionAction;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\StorePlanSubscriptionRequest;
use App\Http\Requests\Api\Professional\UpdatePlanSubscriptionRequest;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use Illuminate\Http\Request;

class SubscriptionController extends ApiController
{
    use ResolveCurrentProfessional;

    /**
     * Get the professional's current subscription
     */
    public function show(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $subscription = $professional->subscription;

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'plan' => [
                    'id' => $subscription->plan->id,
                    'name' => $subscription->plan->name,
                    'price_cents' => $subscription->plan->price_cents,
                    'currency_code' => $subscription->plan->currency_code,
                    'billing_interval' => $subscription->plan->billing_interval,
                    'entitlements' => $subscription->plan->entitlements,
                ],
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end,
                'trial_ends_at' => $subscription->trial_ends_at,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'ended_at' => $subscription->ended_at,
            ],
        ]);
    }

    /**
     * Create a new subscription (typically during signup)
     */
    public function store(StorePlanSubscriptionRequest $request)
    {
        $professional = $this->currentProfessional($request);

        $action = app(CreateProfessionalSubscriptionAction::class);
        $subscription = $action->execute($professional, $request->validated());

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end,
                'trial_ends_at' => $subscription->trial_ends_at,
            ],
        ], 201);
    }

    /**
     * Change the professional's subscription plan
     */
    public function update(UpdatePlanSubscriptionRequest $request)
    {
        $professional = $this->currentProfessional($request);

        $action = app(ChangeProfessionalPlanAction::class);
        $subscription = $action->execute($professional, $request->validated());

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end,
            ],
        ]);
    }

    /**
     * Cancel the professional's subscription
     */
    public function cancel(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $action = app(CancelProfessionalSubscriptionAction::class);
        $subscription = $action->execute($professional);

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'ended_at' => $subscription->ended_at,
            ],
        ]);
    }

    /**
     * Resume a canceled subscription (only if cancel_at_period_end and still within period)
     */
    public function resume(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $subscription = $professional->subscription;

        if (!$subscription) {
            return response()->json([
                'message' => 'No subscription to resume',
            ], 404);
        }

        if (!$subscription->isActive()) {
            return response()->json([
                'message' => 'Subscription is no longer active and cannot be resumed.',
            ], 422);
        }

        if (!$subscription->cancel_at_period_end) {
            return response()->json([
                'message' => 'Subscription is not scheduled for cancellation.',
            ], 422);
        }

        if ($subscription->current_period_end && $subscription->current_period_end->isPast()) {
            return response()->json([
                'message' => 'Subscription period has already ended.',
            ], 422);
        }

        $subscription->update([
            'cancel_at_period_end' => false,
        ]);

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'ended_at' => $subscription->ended_at,
            ],
        ]);
    }
}
