<?php

namespace App\Http\Controllers\Api\Professional;

use App\Actions\Subscription\CancelProfessionalSubscriptionAction;
use App\Actions\Subscription\ChangeProfessionalPlanAction;
use App\Actions\Subscription\CreateProfessionalSubscriptionAction;
use App\Actions\Subscription\ResumeProfessionalSubscriptionAction;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\StorePlanSubscriptionRequest;
use App\Http\Requests\Api\Professional\UpdatePlanSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Billing\Subscription;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Http\Request;

// V2: Subscription lifecycle (create, change plan, cancel, resume, billing portal). Wired to Stripe for paid plans.
class SubscriptionController extends ApiController
{
    use ResolveCurrentProfessional;

    public function show(Request $request)
    {
        $professional = $this->currentProfessional($request);
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

    public function store(StorePlanSubscriptionRequest $request)
    {
        $professional = $this->currentProfessional($request);

        $action = app(CreateProfessionalSubscriptionAction::class);
        $result = $action->execute($professional, $request->validated());

        if (is_array($result)) {
            return response()->json(['data' => $result]);
        }

        return (new SubscriptionResource($result->load('plan')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePlanSubscriptionRequest $request)
    {
        $professional = $this->currentProfessional($request);

        $action = app(ChangeProfessionalPlanAction::class);
        $result = $action->execute($professional, $request->validated());

        if (is_array($result)) {
            return response()->json(['data' => $result]);
        }

        return new SubscriptionResource($result->load('plan'));
    }

    public function cancel(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $action = app(CancelProfessionalSubscriptionAction::class);
        $subscription = $action->execute($professional);

        return new SubscriptionResource($subscription->load('plan'));
    }

    public function resume(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $action = app(ResumeProfessionalSubscriptionAction::class);
        $subscription = $action->execute($professional);

        return new SubscriptionResource($subscription->load('plan'));
    }

    public function billingPortal(Request $request)
    {
        $request->validate([
            'return_url' => ['required', 'url'],
        ]);

        $professional = $this->currentProfessional($request);

        $billing = app(StripeBillingService::class);
        $portalUrl = $billing->createBillingPortalSession($professional, $request->input('return_url'));

        return response()->json([
            'data' => ['portal_url' => $portalUrl],
        ]);
    }

    public function previewPlanChange(Request $request)
    {
        $request->validate([
            'plan_id' => ['required', 'string', 'exists:plans,id'],
        ]);

        $professional = $this->currentProfessional($request);

        $subscription = Subscription::query()
            ->with('plan')
            ->where('professional_id', $professional->id)
            ->whereNull('ended_at')
            ->first();

        if (! $subscription || ! $subscription->isStripeManaged()) {
            return response()->json([
                'message' => 'No Stripe-managed subscription to preview changes for.',
            ], 422);
        }

        $newPlan = \App\Models\Billing\Plan::findOrFail($request->input('plan_id'));

        $billing = app(StripeBillingService::class);
        $preview = $billing->previewPlanChange(
            $subscription->stripe_customer_id,
            $subscription->stripe_subscription_id,
            $newPlan->stripe_price_id,
        );

        return response()->json(['data' => $preview]);
    }
}
