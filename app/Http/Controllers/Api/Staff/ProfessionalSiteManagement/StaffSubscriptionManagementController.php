<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\SubscriptionResource;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Billing\CancelProfessionalSubscriptionAction;
use App\Services\Billing\ChangeProfessionalPlanAction;
use App\Services\Billing\ResumeProfessionalSubscriptionAction;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2: Staff manages professional subscriptions (view, change plan, cancel, resume, preview change, mint portal link). Wired to Stripe for paid plans.
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

    // Mirrors SubscriptionController::previewPlanChange. Read-only Stripe preview;
    // returns the same { data: ... } proration envelope so staff and self-service
    // dashboards can share preview-rendering code.
    public function previewChange(Request $request, Professional $professional): JsonResponse
    {
        $request->validate([
            'plan_id' => ['required', 'string', 'exists:plans,id'],
        ]);

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

        $newPlan = Plan::findOrFail($request->input('plan_id'));

        $billing = app(StripeBillingService::class);
        $preview = $billing->previewPlanChange(
            $subscription->stripe_customer_id,
            $subscription->stripe_subscription_id,
            $newPlan->stripe_price_id,
        );

        return response()->json(['data' => $preview]);
    }

    // Mints a Stripe billing-portal session, then EMAILS the URL to the brand
    // via the `subscriptions` notification channel. The portal URL is never
    // included in the response — only the account holder receives it. Security
    // defence: a compromised staff session must not be able to update the
    // brand's payment method.
    public function billingPortal(Request $request, Professional $professional): JsonResponse
    {
        $request->validate([
            'return_url' => ['required', 'url'],
        ]);

        $billing = app(StripeBillingService::class);
        $portalUrl = $billing->createBillingPortalSession($professional, $request->input('return_url'));

        // Timestamp the dedupe key so re-issuing the link after the previous
        // one expires actually publishes a new notification instead of being
        // silently collapsed by NotificationPublisher's atomic dedupe.
        $dedupeKey = 'subscription.staff_portal.'.$professional->id.'.'.now()->timestamp;

        app(NotificationPublisher::class)->publish(
            professionalId: $professional->id,
            frontendType: 'Info',
            category: 'subscriptions',
            title: 'Update your billing details',
            body: 'Our team has prepared a secure Stripe billing portal link for you. Use the button below to update your card or billing info.',
            dedupeKey: $dedupeKey,
            ctaUrl: $portalUrl,
            primaryActionLabel: 'Open billing portal',
            retentionConfigKey: 'subscription',
        );

        $staff = $request->attributes->get('partna_staff');
        Log::info('staff.subscription.billing_portal_emailed', [
            'professional_id' => $professional->id,
            'staff_id' => is_object($staff) ? ($staff->id ?? null) : null,
        ]);

        return response()->json([
            'data' => [
                'sent' => true,
                'professional_id' => $professional->id,
            ],
        ]);
    }
}
