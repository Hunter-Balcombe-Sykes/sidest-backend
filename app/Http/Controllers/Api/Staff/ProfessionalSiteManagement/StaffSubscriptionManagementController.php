<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Actions\Subscription\ChangeProfessionalPlanAction;
use App\Actions\Subscription\CancelProfessionalSubscriptionAction;
use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// V2: Staff manages professional subscriptions (view, change plan, cancel, resume).
class StaffSubscriptionManagementController extends ApiController
{
    /**
     * GET /api/staff/professionals/{professional}/subscription
     * View the professional's current subscription
     */
    public function show(Professional $professional): JsonResponse
    {
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
     * PATCH /api/staff/professionals/{professional}/subscription
     * Change the professional's subscription plan
     */
    public function update(Request $request, Professional $professional): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'exists:plans,id'],
        ]);

        $subscription = $professional->subscription;

        if (!$subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['Professional has no active subscription.'],
            ]);
        }

        if (!$subscription->isActive()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not active.'],
            ]);
        }

        if ($subscription->plan_id === $data['plan_id']) {
            throw ValidationException::withMessages([
                'plan_id' => ['New plan is the same as current plan.'],
            ]);
        }

        $action = app(ChangeProfessionalPlanAction::class);
        $subscription = $action->execute($professional, $data);

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
     * POST /api/staff/professionals/{professional}/subscription/cancel
     * Cancel the professional's subscription at period end
     */
    public function cancel(Professional $professional): JsonResponse
    {
        $subscription = $professional->subscription;

        if (!$subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['Professional has no active subscription.'],
            ]);
        }

        if (!$subscription->isActive()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not active.'],
            ]);
        }

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
     * POST /api/staff/professionals/{professional}/subscription/resume
     * Resume a subscription that was scheduled to be canceled
     */
    public function resume(Professional $professional): JsonResponse
    {
        $subscription = $professional->subscription;

        if (!$subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['Professional has no subscription to resume.'],
            ]);
        }

        if (!$subscription->isActive()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is no longer active and cannot be resumed.'],
            ]);
        }

        if (!$subscription->cancel_at_period_end) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not scheduled for cancellation.'],
            ]);
        }

        if ($subscription->current_period_end && $subscription->current_period_end->isPast()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription period has already ended.'],
            ]);
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

