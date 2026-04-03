<?php

namespace App\Actions\Subscription;

use App\Models\Core\Professional\Professional;
use Illuminate\Validation\ValidationException;

// V2: Cancels subscription at billing period end (not immediately). Sets cancel_at_period_end flag.
class CancelProfessionalSubscriptionAction
{
    /**
     * Cancel the professional's subscription at period end
     */
    public function execute(Professional $professional)
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

        $subscription->update([
            'cancel_at_period_end' => true,
        ]);

        return $subscription;
    }
}
