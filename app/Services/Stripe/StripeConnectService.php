<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandCommissionTopup;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

// V2: Core. Stripe Connect Express onboarding, payment method collection, wallet management, and manual top-up checkout sessions. Required for affiliate payout flow.
class StripeConnectService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret_key'));
    }

    /**
     * Create a Stripe Connect Express account for a professional/influencer/brand.
     *
     * Requires country_code to be set on the professional. Without it the
     * account would silently default to 'AU' and Stripe would later reject
     * the KYC form for non-AU users. Fail fast instead so the frontend can
     * prompt for country before onboarding.
     */
    public function createConnectAccount(Professional $professional): string
    {
        if (! is_string($professional->country_code) || trim($professional->country_code) === '') {
            abort(
                422,
                'Cannot create a Stripe Connect account without a country. Please set your country on your profile before connecting Stripe.'
            );
        }

        $account = $this->stripe->accounts->create([
            'type' => 'express',
            'country' => $this->mapCountryCode($professional->country_code),
            'email' => $professional->primary_email,
            // Affiliates are individuals/sole traders — pre-selects the individual
            // KYC path so Stripe doesn't prompt with a business-type selector.
            'business_type' => 'individual',
            'metadata' => [
                'sidest_professional_id' => $professional->id,
                'professional_type' => $professional->professional_type,
            ],
            'capabilities' => [
                'transfers' => ['requested' => true],
                'card_payments' => ['requested' => true],
            ],
        ], ['idempotency_key' => "acct_{$professional->id}"]);

        $professional->update([
            'stripe_connect_account_id' => $account->id,
            'stripe_connect_status' => 'onboarding',
            'stripe_grace_period_ends_at' => $professional->stripe_grace_period_ends_at
                ?? now()->addDays((int) config('sidest.store.grace_period_days', 30)),
        ]);

        return $account->id;
    }

    /**
     * Generate an onboarding link for a Connect Express account.
     *
     * If the professional previously soft-disconnected (status='disconnected'
     * but account_id preserved), reset their status to 'onboarding' so the
     * webhook handler stops skipping updates and the next account.updated
     * event flows through normally. Stripe KYC is preserved on the account,
     * so reconnecting is typically a one-click flow.
     */
    public function createOnboardingLink(Professional $professional, string $returnUrl, string $refreshUrl): string
    {
        $accountId = $professional->stripe_connect_account_id;

        if (! $accountId) {
            $accountId = $this->createConnectAccount($professional);
        } else {
            if ($professional->stripe_connect_status === 'disconnected') {
                $professional->update(['stripe_connect_status' => 'onboarding']);
            }

            // Patch business_type to 'individual' on any existing account that
            // hasn't finished onboarding yet. Stripe permits this update while
            // details_submitted is false — once submitted it's locked in.
            try {
                $existing = $this->stripe->accounts->retrieve($accountId);
                if (! $existing->details_submitted && $existing->business_type !== 'individual') {
                    $this->stripe->accounts->update($accountId, ['business_type' => 'individual']);
                }
            } catch (ApiErrorException) {
                // Non-fatal — continue with existing account as-is.
            }
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
     * Check the status of a Connect account and sync it locally.
     *
     * If the professional is in the locally-disconnected state, we skip the
     * Stripe round-trip entirely — their account still exists at Stripe and
     * would return 'active', but the user has explicitly opted out. Let them
     * stay disconnected until they reconnect via createOnboardingLink.
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

        if ($professional->stripe_connect_status === 'disconnected') {
            return [
                'status' => 'disconnected',
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'details_submitted' => false,
            ];
        }

        $account = $this->stripe->accounts->retrieve($accountId);

        $status = self::determineAccountStatus($account);

        if ($professional->stripe_connect_status !== $status) {
            $professional->update(['stripe_connect_status' => $status]);
        }

        return [
            'status' => $status,
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
            'details_submitted' => (bool) $account->details_submitted,
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
     * Soft-disconnect a professional's Stripe Connect account.
     *
     * We preserve the stripe_connect_account_id so reconnection reuses the
     * existing Express account — no Stripe orphan accumulation, no lost KYC
     * data for the affiliate, reconnection is a one-click flow. Express
     * doesn't support a clean "reject/delete" API call for accounts with
     * history, so this local-flag approach is the canonical pattern.
     *
     * The payout service already guards on stripe_connect_status === 'active',
     * so disconnected affiliates don't receive payouts. The webhook handler
     * skips account.updated events for disconnected accounts to prevent
     * late Stripe events from silently re-activating them.
     */
    public function disconnectAccount(Professional $professional): void
    {
        $professional->update([
            'stripe_connect_status' => 'disconnected',
        ]);
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
                'sidest_professional_id' => $brand->id,
                'professional_type' => $brand->professional_type,
            ],
        ], ['idempotency_key' => "customer_{$brand->id}"]);

        $brand->update([
            'stripe_customer_id' => $customer->id,
        ]);

        return $customer->id;
    }

    /**
     * Legacy SetupIntent path (kept for compatibility).
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
                'sidest_professional_id' => $brand->id,
            ],
        ]);

        return [
            'client_secret' => $setupIntent->client_secret,
            'setup_intent_id' => $setupIntent->id,
        ];
    }

    /**
     * Stripe Checkout hosted setup flow for collecting a reusable payment method.
     */
    public function createPaymentMethodSetupCheckoutSession(
        Professional $brand,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $customerId = $brand->stripe_customer_id;

        if (! $customerId) {
            $customerId = $this->createCustomer($brand);
        }

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'setup',
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'success_url' => $this->appendCheckoutSessionParam($successUrl, 'stripe_pm_session_id'),
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'purpose' => 'brand_commission_payment_method',
                'sidest_professional_id' => $brand->id,
            ],
        ]);

        return [
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    /**
     * Sync payment method from a completed hosted setup session.
     */
    public function syncPaymentMethodFromCheckoutSession(Professional $brand, string $sessionId): array
    {
        $session = $this->stripe->checkout->sessions->retrieve($sessionId, [
            'expand' => ['setup_intent'],
        ]);

        if (($session->mode ?? null) !== 'setup') {
            throw new \RuntimeException('Checkout session is not a setup session.');
        }

        if (($session->status ?? null) !== 'complete') {
            throw new \RuntimeException('Setup session is not complete yet.');
        }

        $metadataProId = $session->metadata?->sidest_professional_id ?? null;
        if ($metadataProId && $metadataProId !== $brand->id) {
            throw new \RuntimeException('Setup session does not belong to this account.');
        }

        $sessionCustomerId = is_string($session->customer)
            ? $session->customer
            : ($session->customer->id ?? null);

        if ($brand->stripe_customer_id && $sessionCustomerId && $brand->stripe_customer_id !== $sessionCustomerId) {
            throw new \RuntimeException('Setup session customer does not match account customer.');
        }

        if (! $brand->stripe_customer_id && $sessionCustomerId) {
            $brand->update(['stripe_customer_id' => $sessionCustomerId]);
            $brand->refresh();
        }

        $setupIntentId = is_string($session->setup_intent)
            ? $session->setup_intent
            : ($session->setup_intent->id ?? null);

        if (! $setupIntentId) {
            throw new \RuntimeException('Setup session missing setup intent.');
        }

        $setupIntent = $this->stripe->setupIntents->retrieve($setupIntentId, [
            'expand' => ['payment_method'],
        ]);

        if (($setupIntent->status ?? null) !== 'succeeded') {
            throw new \RuntimeException('Setup intent has not succeeded.');
        }

        $paymentMethodId = is_string($setupIntent->payment_method)
            ? $setupIntent->payment_method
            : ($setupIntent->payment_method->id ?? null);

        if (! $paymentMethodId) {
            throw new \RuntimeException('No payment method found on setup intent.');
        }

        $this->savePaymentMethod($brand, $paymentMethodId);

        return [
            'payment_method_id' => $paymentMethodId,
            'setup_intent_id' => $setupIntentId,
        ];
    }

    /**
     * Save the brand's default payment method.
     */
    public function savePaymentMethod(Professional $brand, string $paymentMethodId): void
    {
        $brand->update([
            'stripe_payment_method_id' => $paymentMethodId,
        ]);

        if ($brand->stripe_customer_id) {
            $this->stripe->customers->update($brand->stripe_customer_id, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);
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
     * Remove a brand's payment method and customer linkage.
     */
    public function removeBrandPaymentSetup(Professional $brand): void
    {
        $brand->update([
            'stripe_customer_id' => null,
            'stripe_payment_method_id' => null,
        ]);
    }

    /**
     * Set commission funding mode for a brand.
     */
    public function setCommissionFundingMode(Professional $brand, string $mode): void
    {
        $mode = in_array($mode, ['auto_charge', 'manual_topup'], true) ? $mode : 'auto_charge';

        $brand->update([
            'stripe_commission_funding_mode' => $mode,
        ]);
    }

    /**
     * Create a hosted Checkout payment session for manual brand top-up.
     */
    public function createManualTopUpCheckoutSession(
        Professional $brand,
        int $amountCents,
        string $currency,
        string $successUrl,
        string $cancelUrl,
    ): array {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Top-up amount must be greater than zero.');
        }

        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            $currency = strtoupper((string) ($brand->stripe_manual_balance_currency ?: 'AUD'));
        }

        $customerId = $brand->stripe_customer_id;
        if (! $customerId) {
            $customerId = $this->createCustomer($brand);
        }

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'success_url' => $this->appendCheckoutSessionParam($successUrl, 'stripe_topup_session_id'),
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $amountCents,
                    'product_data' => [
                        'name' => 'Commission Wallet Top-up',
                        'description' => 'Manual funding for commission payouts',
                    ],
                ],
            ]],
            'metadata' => [
                'purpose' => 'brand_commission_topup',
                'sidest_professional_id' => $brand->id,
                'currency' => $currency,
            ],
        ]);

        return [
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    /**
     * Confirm a completed top-up Checkout session and credit the brand balance.
     * Idempotent by checkout session id.
     */
    public function confirmManualTopUpCheckoutSession(Professional $brand, string $sessionId): array
    {
        $existing = BrandCommissionTopup::query()
            ->where('stripe_checkout_session_id', $sessionId)
            ->first();

        if ($existing) {
            $brand->refresh();

            return [
                'status' => 'already_applied',
                'balance_cents' => (int) ($brand->stripe_manual_balance_cents ?? 0),
                'currency_code' => strtoupper((string) ($brand->stripe_manual_balance_currency ?: 'AUD')),
                'topup_id' => $existing->id,
            ];
        }

        $session = $this->stripe->checkout->sessions->retrieve($sessionId);

        if (($session->mode ?? null) !== 'payment') {
            throw new \RuntimeException('Checkout session is not a payment session.');
        }

        if (($session->payment_status ?? null) !== 'paid') {
            throw new \RuntimeException('Top-up payment is not completed yet.');
        }

        $metadataProId = $session->metadata?->sidest_professional_id ?? null;
        if ($metadataProId && $metadataProId !== $brand->id) {
            throw new \RuntimeException('Top-up session does not belong to this account.');
        }

        $purpose = $session->metadata?->purpose ?? null;
        if ($purpose !== 'brand_commission_topup') {
            throw new \RuntimeException('Invalid top-up session purpose.');
        }

        $amountCents = (int) ($session->amount_total ?? 0);
        if ($amountCents <= 0) {
            throw new \RuntimeException('Top-up amount is invalid.');
        }

        $currency = strtoupper((string) ($session->currency ?: ($brand->stripe_manual_balance_currency ?: 'AUD')));
        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : ($session->payment_intent->id ?? null);

        return DB::transaction(function () use ($brand, $sessionId, $paymentIntentId, $amountCents, $currency) {
            $lockedBrand = Professional::query()
                ->whereKey($brand->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingTopup = BrandCommissionTopup::query()
                ->where('stripe_checkout_session_id', $sessionId)
                ->first();

            if ($existingTopup) {
                return [
                    'status' => 'already_applied',
                    'balance_cents' => (int) ($lockedBrand->stripe_manual_balance_cents ?? 0),
                    'currency_code' => strtoupper((string) ($lockedBrand->stripe_manual_balance_currency ?: 'AUD')),
                    'topup_id' => $existingTopup->id,
                ];
            }

            $currentBalance = (int) ($lockedBrand->stripe_manual_balance_cents ?? 0);
            $currentCurrency = strtoupper((string) ($lockedBrand->stripe_manual_balance_currency ?: $currency));

            if ($currentCurrency !== $currency && $currentBalance > 0) {
                throw new \RuntimeException(
                    sprintf(
                        'Top-up currency %s does not match existing wallet currency %s.',
                        $currency,
                        $currentCurrency,
                    )
                );
            }

            if ($currentCurrency !== $currency) {
                $lockedBrand->stripe_manual_balance_currency = $currency;
            }

            $lockedBrand->stripe_manual_balance_cents = $currentBalance + $amountCents;
            $lockedBrand->save();

            $topup = BrandCommissionTopup::create([
                'brand_professional_id' => $lockedBrand->id,
                'stripe_checkout_session_id' => $sessionId,
                'stripe_payment_intent_id' => $paymentIntentId,
                'amount_cents' => $amountCents,
                'currency_code' => $currency,
                'status' => 'completed',
            ]);

            return [
                'status' => 'applied',
                'balance_cents' => (int) $lockedBrand->stripe_manual_balance_cents,
                'currency_code' => strtoupper((string) $lockedBrand->stripe_manual_balance_currency),
                'topup_id' => $topup->id,
            ];
        });
    }

    private function appendCheckoutSessionParam(string $url, string $param): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.$param.'={CHECKOUT_SESSION_ID}';
    }

    /**
     * Determine the Connect account status from a Stripe Account object.
     * Shared by both the service and the webhook controller.
     */
    public static function determineAccountStatus(object $account): string
    {
        if ($account->charges_enabled && $account->payouts_enabled && $account->details_submitted) {
            return 'active';
        }

        if ($account->requirements?->disabled_reason ?? null) {
            return 'restricted';
        }

        return 'onboarding';
    }

    private function mapCountryCode(?string $code): string
    {
        if (! $code) {
            return 'AU';
        }

        return strtoupper($code);
    }
}
