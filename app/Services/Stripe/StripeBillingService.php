<?php

namespace App\Services\Stripe;

use App\Models\Billing\Plan;
use App\Models\Core\Professional\Professional;
use Stripe\StripeClient;

// V2: Core. Wraps Stripe Billing API calls: customer creation, checkout sessions, subscription management, billing portal, and invoice previews.
class StripeBillingService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(array_filter([
            'api_key' => config('services.stripe.secret_key'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));
    }

    /**
     * Ensure the professional has a Stripe Customer. Reuses existing one
     * (may have been created by StripeConnectService for commission charges).
     */
    public function ensureStripeCustomer(Professional $professional): string
    {
        if ($professional->stripe_customer_id) {
            return $professional->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create([
            'email' => $professional->primary_email,
            'name' => $professional->display_name,
            'metadata' => [
                'sidest_professional_id' => $professional->id,
                'professional_type' => $professional->professional_type,
            ],
        ], ['idempotency_key' => "customer_{$professional->id}"]);

        $professional->update([
            'stripe_customer_id' => $customer->id,
        ]);

        return $customer->id;
    }

    /**
     * Create a Stripe Checkout Session in subscription mode.
     * Returns the checkout URL for frontend redirect.
     */
    public function createCheckoutSession(
        Professional $professional,
        Plan $plan,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $customerId = $this->ensureStripeCustomer($professional);

        $session = $this->stripe->checkout->sessions->create([
            'customer' => $customerId,
            'mode' => 'subscription',
            'line_items' => [
                [
                    'price' => $plan->stripe_price_id,
                    'quantity' => 1,
                ],
            ],
            'subscription_data' => [
                'metadata' => [
                    'sidest_professional_id' => $professional->id,
                    'sidest_plan_key' => $plan->plan_key,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'sidest_professional_id' => $professional->id,
                'sidest_plan_id' => $plan->id,
            ],
        ]);

        return [
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    /**
     * Create a Stripe Billing Portal session for self-service management.
     */
    public function createBillingPortalSession(Professional $professional, string $returnUrl): string
    {
        if (! $professional->stripe_customer_id) {
            throw new \RuntimeException('Professional does not have a Stripe customer ID.');
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $professional->stripe_customer_id,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    /**
     * Update the price on an existing Stripe subscription (plan change).
     */
    public function updateSubscriptionPlan(
        string $stripeSubscriptionId,
        Plan $newPlan,
        string $prorationBehavior = 'create_prorations',
    ): \Stripe\Subscription {
        $subscription = $this->stripe->subscriptions->retrieve($stripeSubscriptionId);
        $itemId = $subscription->items->data[0]->id;

        return $this->stripe->subscriptions->update($stripeSubscriptionId, [
            'items' => [
                [
                    'id' => $itemId,
                    'price' => $newPlan->stripe_price_id,
                ],
            ],
            'proration_behavior' => $prorationBehavior,
            'metadata' => [
                'sidest_plan_key' => $newPlan->plan_key,
            ],
        ]);
    }

    /**
     * Cancel a subscription at the end of the current billing period.
     */
    public function cancelSubscriptionAtPeriodEnd(string $stripeSubscriptionId): \Stripe\Subscription
    {
        return $this->stripe->subscriptions->update($stripeSubscriptionId, [
            'cancel_at_period_end' => true,
        ]);
    }

    /**
     * Resume a subscription that was scheduled for cancellation.
     */
    public function resumeSubscription(string $stripeSubscriptionId): \Stripe\Subscription
    {
        return $this->stripe->subscriptions->update($stripeSubscriptionId, [
            'cancel_at_period_end' => false,
        ]);
    }

    /**
     * Cancel a subscription immediately (paid-to-free downgrade or staff override).
     */
    public function cancelSubscriptionImmediately(string $stripeSubscriptionId): \Stripe\Subscription
    {
        return $this->stripe->subscriptions->cancel($stripeSubscriptionId);
    }

    /**
     * Preview proration for a plan change using invoice preview.
     */
    public function previewPlanChange(
        string $stripeCustomerId,
        string $stripeSubscriptionId,
        string $newPriceId,
    ): array {
        $subscription = $this->stripe->subscriptions->retrieve($stripeSubscriptionId);
        $itemId = $subscription->items->data[0]->id;

        $preview = $this->stripe->invoices->createPreview([
            'customer' => $stripeCustomerId,
            'subscription' => $stripeSubscriptionId,
            'subscription_details' => [
                'items' => [
                    [
                        'id' => $itemId,
                        'price' => $newPriceId,
                    ],
                ],
                'proration_behavior' => 'create_prorations',
            ],
        ]);

        return [
            'amount_due' => $preview->amount_due,
            'currency' => $preview->currency,
            'lines' => collect($preview->lines->data)->map(fn ($line) => [
                'description' => $line->description,
                'amount' => $line->amount,
                'proration' => $line->proration,
            ])->all(),
        ];
    }
}
