<?php

namespace App\Actions\Subscription;

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// V2: Creates subscription — free plans are local-only, paid plans go through Stripe Checkout.
class CreateProfessionalSubscriptionAction
{
    public function __construct(private StripeBillingService $billing) {}

    /**
     * Create a new subscription for a professional.
     *
     * Returns Subscription for free plans, or a checkout array for paid plans.
     *
     * @return Subscription|array{checkout_url: string, session_id: string}
     */
    public function execute(Professional $professional, array $data): Subscription|array
    {
        // Check if professional already has an active subscription
        $existing = Subscription::query()
            ->where('professional_id', $professional->id)
            ->whereNull('ended_at')
            ->first();

        if ($existing && $existing->isActive()) {
            throw ValidationException::withMessages([
                'plan_id' => ['Professional already has an active subscription.'],
            ]);
        }

        $plan = Plan::findOrFail($data['plan_id']);

        $this->validatePlanAuthorization($professional, $plan);

        // Free plan: create local subscription immediately
        if ($plan->plan_key === 'free') {
            return Subscription::create([
                'id' => Str::uuid()->toString(),
                'professional_id' => $professional->id,
                'plan_id' => $plan->id,
                'provider' => 'internal',
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => null,
                'cancel_at_period_end' => false,
            ]);
        }

        // Paid plan: create Stripe Checkout Session.
        // Do NOT end the existing free subscription here — the webhook
        // for customer.subscription.created will end it atomically.
        return $this->billing->createCheckoutSession(
            $professional,
            $plan,
            $data['success_url'],
            $data['cancel_url'],
        );
    }

    private function validatePlanAuthorization(Professional $professional, Plan $plan): void
    {
        $type = $professional->professional_type;
        $key = $plan->plan_key;

        // Brands can only subscribe to 'brands' plan
        if ($type === 'brand' && $key !== 'brands') {
            throw ValidationException::withMessages([
                'plan_id' => ['This plan is not available for brand accounts.'],
            ]);
        }

        // Non-brands (affiliates/professionals/influencers) cannot subscribe to 'brands' plan
        if ($type !== 'brand' && $key === 'brands') {
            throw ValidationException::withMessages([
                'plan_id' => ['This plan is only available for brand accounts.'],
            ]);
        }
    }
}
