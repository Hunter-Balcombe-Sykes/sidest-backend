<?php

namespace App\Actions\Subscription;

use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// V2: Resumes a subscription scheduled for cancellation. Clears cancel_at_period_end on both Stripe and local DB.
class ResumeProfessionalSubscriptionAction
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
                'subscription' => ['No subscription to resume.'],
            ]);
        }

        if (! $subscription->isActive()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is no longer active and cannot be resumed.'],
            ]);
        }

        if (! $subscription->cancel_at_period_end) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not scheduled for cancellation.'],
            ]);
        }

        if ($subscription->current_period_end && $subscription->current_period_end->isPast()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription period has already ended.'],
            ]);
        }

        DB::transaction(function () use ($subscription) {
            $subscription->update(['cancel_at_period_end' => false]);

            if ($subscription->isStripeManaged() && $subscription->stripe_subscription_id) {
                $this->billing->resumeSubscription($subscription->stripe_subscription_id);
            }
        });

        return $subscription->fresh();
    }
}
