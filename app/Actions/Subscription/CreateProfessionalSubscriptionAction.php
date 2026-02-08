<?php

namespace App\Actions\Subscription;

use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateProfessionalSubscriptionAction
{
    /**
     * Create a new subscription for a professional
     */
    public function execute(Professional $professional, array $data): Subscription
    {
        // Check if professional already has an active subscription
        if ($professional->subscription && $professional->subscription->isActive()) {
            throw ValidationException::withMessages([
                'plan_id' => ['Professional already has an active subscription.'],
            ]);
        }

        $planId = $data['plan_id'];
        $trialDays = $data['trial_period_days'] ?? null;

        $subscription = Subscription::create([
            'id' => Str::uuid(),
            'professional_id' => $professional->id,
            'plan_id' => $planId,
            'status' => 'trialing',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'trial_ends_at' => $trialDays ? now()->addDays($trialDays) : null,
            'cancel_at_period_end' => false,
            'provider_payload' => [],
        ]);

        return $subscription;
    }
}
