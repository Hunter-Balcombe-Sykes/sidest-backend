<?php

namespace App\Actions\Subscription;

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Validation\ValidationException;

// V2: Changes professional's subscription plan. Handles free->paid (checkout), paid->paid (Stripe update), and paid->free (cancel + fallback).
class ChangeProfessionalPlanAction
{
    public function __construct(private StripeBillingService $billing) {}

    /**
     * Change the professional's current plan.
     * On paid→paid switches, plan_id and cancel_at_period_end are reconciled
     * asynchronously via the customer.subscription.updated webhook.
     *
     * @return Subscription|array{checkout_url: string, session_id: string}
     */
    public function execute(Professional $professional, array $data): Subscription|array
    {
        $subscription = Subscription::query()
            ->where('professional_id', $professional->id)
            ->whereNull('ended_at')
            ->first();

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['Professional has no active subscription.'],
            ]);
        }

        if (! $subscription->isActive() && ! $subscription->isInGracePeriod()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not active.'],
            ]);
        }

        $newPlan = Plan::findOrFail($data['plan_id']);

        // Enforce plan authorization by professional type
        $type = $professional->professional_type;
        if ($type === 'brand' && $newPlan->plan_key !== 'brands' && $newPlan->plan_key !== 'free') {
            throw ValidationException::withMessages([
                'plan_id' => ['This plan is not available for brand accounts.'],
            ]);
        }
        if ($type !== 'brand' && $newPlan->plan_key === 'brands') {
            throw ValidationException::withMessages([
                'plan_id' => ['This plan is only available for brand accounts.'],
            ]);
        }

        if ($subscription->plan_id === $newPlan->id) {
            throw ValidationException::withMessages([
                'plan_id' => ['New plan is the same as current plan.'],
            ]);
        }

        // Free -> Paid: need Stripe Checkout (no payment method on file)
        if ($subscription->isFreeInternal()) {
            return $this->billing->createCheckoutSession(
                $professional,
                $newPlan,
                $data['success_url'],
                $data['cancel_url'],
            );
        }

        // Paid -> Free: cancel Stripe subscription, webhook handles free fallback
        if ($newPlan->plan_key === 'free') {
            $this->billing->cancelSubscriptionImmediately($subscription->stripe_subscription_id);

            // The webhook for customer.subscription.deleted will:
            // 1. Set ended_at on this subscription
            // 2. Create a free internal subscription for affiliates
            return $subscription->fresh();
        }

        // Paid -> Paid: update price on Stripe; customer.subscription.updated webhook
        // reconciles plan_id and cancel_at_period_end locally (same as paid->free path).
        $this->billing->updateSubscriptionPlan(
            $subscription->stripe_subscription_id,
            $newPlan,
        );

        return $subscription->fresh();
    }
}
