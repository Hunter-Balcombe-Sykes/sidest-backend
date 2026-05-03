<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// V2: API resource for subscription responses — includes plan details, billing period, and Stripe subscription ID when applicable.
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => [
                'id' => $this->plan->id,
                'plan_key' => $this->plan->plan_key,
                'name' => $this->plan->name,
                'price_cents' => $this->plan->price_cents,
                'currency_code' => $this->plan->currency_code,
                'billing_interval' => $this->plan->billing_interval,
                'entitlements' => $this->plan->entitlements,
            ],
            'provider' => $this->provider,
            'status' => $this->status,
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'ended_at' => $this->ended_at,
            'stripe_subscription_id' => $this->when($this->isStripeManaged(), $this->stripe_subscription_id),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
