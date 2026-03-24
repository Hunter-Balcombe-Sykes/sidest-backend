<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class StripeConnectService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret_key'));
    }

    /**
     * Create a Stripe Connect Express account for a professional/influencer
     * so they can receive commission payouts.
     */
    public function createConnectAccount(Professional $professional): string
    {
        $account = $this->stripe->accounts->create([
            'type' => 'express',
            'country' => $this->mapCountryCode($professional->country_code),
            'email' => $professional->primary_email,
            'metadata' => [
                'comet_professional_id' => $professional->id,
                'professional_type' => $professional->professional_type,
            ],
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
        ]);

        $professional->update([
            'stripe_connect_account_id' => $account->id,
            'stripe_connect_status' => 'onboarding',
        ]);

        return $account->id;
    }

    /**
     * Generate an onboarding link for a Connect Express account.
     */
    public function createOnboardingLink(Professional $professional, string $returnUrl, string $refreshUrl): string
    {
        $accountId = $professional->stripe_connect_account_id;

        if (! $accountId) {
            $accountId = $this->createConnectAccount($professional);
        }

        $link = $this->stripe->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return $link->url;
    }

    /**
     * Create a Stripe Customer for a brand so we can charge them for commissions.
     */
    public function createCustomer(Professional $brand): string
    {
        $customer = $this->stripe->customers->create([
            'email' => $brand->primary_email,
            'name' => $brand->display_name,
            'metadata' => [
                'comet_professional_id' => $brand->id,
                'professional_type' => $brand->professional_type,
            ],
        ]);

        $brand->update([
            'stripe_customer_id' => $customer->id,
        ]);

        return $customer->id;
    }

    /**
     * Create a Stripe SetupIntent so the brand can save a payment method
     * for future commission charges.
     */
    public function createSetupIntent(Professional $brand): array
    {
        $customerId = $brand->stripe_customer_id;

        if (! $customerId) {
            $customerId = $this->createCustomer($brand);
        }

        $setupIntent = $this->stripe->setupIntents->create([
            'customer' => $customerId,
            'payment_method_types' => ['card', 'au_becs_debit'],
            'metadata' => [
                'comet_professional_id' => $brand->id,
            ],
        ]);

        return [
            'client_secret' => $setupIntent->client_secret,
            'setup_intent_id' => $setupIntent->id,
        ];
    }

    /**
     * Save the brand's default payment method after SetupIntent confirmation.
     */
    public function savePaymentMethod(Professional $brand, string $paymentMethodId): void
    {
        $brand->update([
            'stripe_payment_method_id' => $paymentMethodId,
        ]);

        // Set as the default payment method on the Stripe Customer
        if ($brand->stripe_customer_id) {
            $this->stripe->customers->update($brand->stripe_customer_id, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);
        }
    }

    /**
     * Check the status of a Connect account and sync it locally.
     */
    public function syncAccountStatus(Professional $professional): array
    {
        $accountId = $professional->stripe_connect_account_id;

        if (! $accountId) {
            return [
                'status' => 'not_connected',
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'details_submitted' => false,
            ];
        }

        $account = $this->stripe->accounts->retrieve($accountId);

        $status = 'onboarding';
        if ($account->charges_enabled && $account->payouts_enabled && $account->details_submitted) {
            $status = 'active';
        } elseif ($account->requirements?->disabled_reason) {
            $status = 'restricted';
        }

        if ($professional->stripe_connect_status !== $status) {
            $professional->update(['stripe_connect_status' => $status]);
        }

        return [
            'status' => $status,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'details_submitted' => $account->details_submitted,
            'requirements' => $account->requirements?->currently_due ?? [],
        ];
    }

    /**
     * Get the Stripe Express dashboard login link for a connected account.
     */
    public function createDashboardLink(Professional $professional): ?string
    {
        $accountId = $professional->stripe_connect_account_id;

        if (! $accountId || $professional->stripe_connect_status !== 'active') {
            return null;
        }

        try {
            $link = $this->stripe->accounts->createLoginLink($accountId);
            return $link->url;
        } catch (ApiErrorException) {
            return null;
        }
    }

    /**
     * Check if a brand has a valid payment method for commission charges.
     */
    public function brandHasPaymentMethod(Professional $brand): bool
    {
        return $brand->stripe_customer_id !== null
            && $brand->stripe_payment_method_id !== null;
    }

    /**
     * Get the payment methods for a brand's customer.
     */
    public function listPaymentMethods(Professional $brand): array
    {
        if (! $brand->stripe_customer_id) {
            return [];
        }

        $methods = $this->stripe->paymentMethods->all([
            'customer' => $brand->stripe_customer_id,
            'type' => 'card',
        ]);

        return array_map(fn ($m) => [
            'id' => $m->id,
            'brand' => $m->card?->brand,
            'last4' => $m->card?->last4,
            'exp_month' => $m->card?->exp_month,
            'exp_year' => $m->card?->exp_year,
            'is_default' => $m->id === $brand->stripe_payment_method_id,
        ], $methods->data);
    }

    /**
     * Disconnect a professional's Stripe Connect account.
     */
    public function disconnectAccount(Professional $professional): void
    {
        $professional->update([
            'stripe_connect_account_id' => null,
            'stripe_connect_status' => 'not_connected',
        ]);
    }

    /**
     * Remove a brand's payment method and customer.
     */
    public function removeBrandPaymentSetup(Professional $brand): void
    {
        $brand->update([
            'stripe_customer_id' => null,
            'stripe_payment_method_id' => null,
        ]);
    }

    private function mapCountryCode(?string $code): string
    {
        if (! $code) {
            return 'AU';
        }

        return strtoupper($code);
    }
}
