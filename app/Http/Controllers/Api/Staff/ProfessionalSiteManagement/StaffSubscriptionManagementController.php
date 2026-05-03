<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Actions\Subscription\CancelProfessionalSubscriptionAction;
use App\Actions\Subscription\ChangeProfessionalPlanAction;
use App\Actions\Subscription\ResumeProfessionalSubscriptionAction;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\SubscriptionResource;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff manages professional subscriptions (view, change plan, cancel, resume). Wired to Stripe for paid plans.
class StaffSubscriptionManagementController extends ApiController
{
    public function show(Professional $professional): JsonResponse|SubscriptionResource
    {
        $subscription = Subscription::query()
            ->with('plan')
            ->where('professional_id', $professional->id)
            ->whereNull('ended_at')
            ->latest('created_at')
            ->first();

        if (! $subscription) {
            return response()->json([
                'message' => 'No active subscription',
            ], 404);
        }

        return new SubscriptionResource($subscription);
    }

    public function update(Request $request, Professional $professional): JsonResponse|SubscriptionResource
    {
        $data = $request->validate([
            'plan_id' => ['required', 'string', 'exists:plans,id'],
            'success_url' => ['sometimes', 'nullable', 'url'],
            'cancel_url' => ['sometimes', 'nullable', 'url'],
        ]);

        $action = app(ChangeProfessionalPlanAction::class);
        $result = $action->execute($professional, $data);

        if (is_array($result)) {
            return response()->json(['data' => $result]);
        }

        return new SubscriptionResource($result->load('plan'));
    }

    public function cancel(Professional $professional): SubscriptionResource
    {
        $action = app(CancelProfessionalSubscriptionAction::class);
        $subscription = $action->execute($professional);

        return new SubscriptionResource($subscription->load('plan'));
    }

    public function resume(Professional $professional): SubscriptionResource
    {
        $action = app(ResumeProfessionalSubscriptionAction::class);
        $subscription = $action->execute($professional);

        return new SubscriptionResource($subscription->load('plan'));
    }
}
