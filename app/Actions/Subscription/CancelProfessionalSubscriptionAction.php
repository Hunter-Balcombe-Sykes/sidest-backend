<?php

namespace App\Actions\Subscription;

use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Validation\ValidationException;

// V2: Cancels subscription at billing period end. Stripe-managed subs call Stripe API; free plans are rejected.
class CancelProfessionalSubscriptionAction
{
    public function __construct(private StripeBillingService $billing) {}

    public function execute(Professional $professional): Subscription
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

        if (! $subscription->isActive()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not active.'],
            ]);
        }

        if ($subscription->isFreeInternal()) {
            throw ValidationException::withMessages([
                'subscription' => ['Free subscriptions cannot be canceled.'],
            ]);
        }

        // Cancel on Stripe side at period end
        $this->billing->cancelSubscriptionAtPeriodEnd($subscription->stripe_subscription_id);

        $subscription->update([
            'cancel_at_period_end' => true,
        ]);

        return $subscription->fresh();
    }
}
