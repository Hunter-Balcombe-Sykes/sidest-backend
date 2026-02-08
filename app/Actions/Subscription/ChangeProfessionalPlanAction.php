<?php

namespace App\Actions\Subscription;

use App\Models\Core\Professional\Professional;
use Illuminate\Validation\ValidationException;

class ChangeProfessionalPlanAction
{
    /**
     * Change the professional's current plan
     */
    public function execute(Professional $professional, array $data)
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

        $newPlanId = $data['plan_id'];

        if ($subscription->plan_id === $newPlanId) {
            throw ValidationException::withMessages([
                'plan_id' => ['New plan is the same as current plan.'],
            ]);
        }

        // Update to the new plan
        $subscription->update([
            'plan_id' => $newPlanId,
            // Reset cancel_at_period_end since they're staying
            'cancel_at_period_end' => false,
        ]);

        return $subscription;
    }
}
