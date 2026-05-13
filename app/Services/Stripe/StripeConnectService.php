<?php

namespace App\Services\Stripe;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

// V2: Core. Stripe Connect Express onboarding, payment method collection, wallet management, and manual top-up checkout sessions. Required for affiliate payout flow.
class StripeConnectService
{
    private StripeClient $stripe;

    /**
     * Cache TTL (seconds) for the syncAccountStatus payload. Short on purpose:
     * Stripe Connect status changes are eventual via webhooks, so a 60s window
     * bounds how long a non-onboarding caller can see stale charges_enabled /
     * payouts_enabled data when the account.updated webhook is in transit.
     */
    private const STATUS_CACHE_TTL = 60;

    public function __construct(private readonly CacheLockService $cacheLock)
    {
        $this->stripe = new StripeClient(array_filter([
            'api_key' => config('services.stripe.secret_key'),
            'stripe_version' => config('services.stripe.api_version'),
        ]));
    }

    /**
     * Cache key for a connected account's status payload. Keyed on the Stripe
     * account ID (not the professional ID) so the webhook bust path can
     * forget the cache without a DB lookup — the webhook already carries the
     * account ID in event->account.
     *
     * Static so the webhook handler can bust the cache without instantiating
     * the service (which would force-load the Stripe SDK with a real secret).
     */
    public static function statusCacheKey(string $accountId): string
    {
        return 'stripe:connect:status:'.$accountId;
    }

    /**
     * Evict the cached account status. MUST clear the SWR ":stale" copy too —
     * forgetting only the primary key would leave the stale-while-revalidate
     * last-good copy live for up to 10×TTL, defeating the bust entirely.
     *
     * Called from:
     *   - StripeConnectController@status when the request includes ?fresh=1
     *     (post-onboarding redirect; user must see live state, not cached).
     *   - StripeConnectWebhookController on every account.updated event.
     */
    public static function forgetStatusCache(string $accountId): void
    {
        $key = self::statusCacheKey($accountId);
        Cache::forget($key);
        Cache::forget($key.':stale');
    }

    /**
     * Create a Stripe Connect Express account for a professional/influencer/brand.
     *
     * Requires country_code to be set on the professional. Without it the
     * account would silently default to 'AU' and Stripe would later reject
     * the KYC form for non-AU users. Fail fast instead so the frontend can
     * prompt for country before onboarding.
     *
     * Brands and affiliates take different paths:
     *   - Affiliate → business_type=individual, transfers capability only.
     *     Affiliates only RECEIVE transfers — they never process customer
     *     payments themselves.
     *   - Brand → business_type=company, BOTH transfers AND card_payments
     *     capabilities. Brands need card_payments so we can run the
     *     commission charge as a direct charge on their account (brand =
     *     merchant of record on the customer's statement), and transfers so
     *     the funds can flow on from brand → affiliate. Stripe forces these
     *     two capabilities together — see
     *     https://docs.stripe.com/connect/account-capabilities.
     */
    public function createConnectAccount(Professional $professional): string
    {
        if (! is_string($professional->country_code) || trim($professional->country_code) === '') {
            abort(
                422,
                'Cannot create a Stripe Connect account without a country. Please set your country on your profile before connecting Stripe.'
            );
        }

        $isBrand = $professional->isBrand();

        $capabilities = ['transfers' => ['requested' => true]];
        if ($isBrand) {
            // Direct-charge commission payouts require the connected account to
            // accept card payments. Without card_payments the PaymentIntent
            // with stripe_account=brand option would 400 immediately.
            $capabilities['card_payments'] = ['requested' => true];
        }

        $accountPayload = array_merge([
            'type' => 'express',
            'country' => $this->mapCountryCode($professional->country_code),
            'email' => $professional->primary_email,
            'business_type' => $isBrand ? 'company' : 'individual',
            'metadata' => [
                'sidest_professional_id' => $professional->id,
                'professional_type' => $professional->professional_type,
            ],
            'capabilities' => $capabilities,
        ], $this->buildAccountPrefillPayload($professional));

        $account = $this->stripe->accounts->create(
            $accountPayload,
            ['idempotency_key' => "acct_{$professional->id}"],
        );

        $update = [
            'stripe_connect_account_id' => $account->id,
            'stripe_connect_status' => 'onboarding',
            'stripe_grace_period_ends_at' => $professional->stripe_grace_period_ends_at
                ?? now()->addDays((int) config('partna.store.signup_grace_period_days', 30)),
        ];

        // Seed wallet currency from Shopify's shop_currency so brands with
        // non-AUD stores don't get locked into the AUD DB default.
        $shopCurrency = $this->resolveShopCurrency($professional);
        if ($shopCurrency) {
            $update['stripe_manual_balance_currency'] = $shopCurrency;
        }

        $professional->update($update);

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

            // Patch business_type on any existing account that hasn't finished
            // onboarding yet — brands get 'company', everyone else 'individual'.
            // Stripe permits this update while details_submitted is false; once
            // submitted it's locked in.
            $desiredBusinessType = $professional->isBrand() ? 'company' : 'individual';
            try {
                $existing = $this->stripe->accounts->retrieve($accountId);
                if (! $existing->details_submitted && $existing->business_type !== $desiredBusinessType) {
                    $this->stripe->accounts->update($accountId, ['business_type' => $desiredBusinessType]);
                }
            } catch (ApiErrorException) {
                // Non-fatal — continue with existing account as-is.
            }
        }

        // Inject ?fresh=1 so the dashboard's first /stripe/status call after
        // Stripe redirects the user back skips the cache. Without this the
        // post-onboarding screen would race the account.updated webhook and
        // typically render the pre-onboarding state for several seconds.
        $returnUrlWithBypass = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'fresh=1';

        $link = $this->stripe->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrlWithBypass,
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

        // Cache wraps the Stripe round-trip only — the early-return branches
        // above are already free. Bust paths: ?fresh=1 on the controller
        // (post-onboarding redirect) and account.updated webhook.
        return $this->cacheLock->rememberLocked(
            self::statusCacheKey($accountId),
            self::STATUS_CACHE_TTL,
            fn () => $this->fetchAndSyncAccountStatus($professional, $accountId),
        );
    }

    /**
     * Inner closure for syncAccountStatus — single Stripe call + DB sync, then
     * the response shape consumed by /api/stripe/status.
     *
     * @return array{status: string, charges_enabled: bool, payouts_enabled: bool, details_submitted: bool, requirements: array<int, string>}
     */
    private function fetchAndSyncAccountStatus(Professional $professional, string $accountId): array
    {
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

        // Allow restricted accounts to access their dashboard so they can resolve
        // KYC issues themselves. Previously this was 'active'-only which locked
        // out the exact users who most needed the dashboard. Disconnected/onboarding
        // accounts still return null — the frontend routes them to the onboarding
        // link instead.
        if (! $accountId || ! in_array($professional->stripe_connect_status, ['active', 'restricted'], true)) {
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
     * Create a Stripe Customer scoped to the BRAND'S OWN Connect account so the
     * saved card lives on the same account that will later run the commission
     * direct charge. Brand must already be onboarded — Stripe rejects customer
     * creates on accounts that don't have card_payments capability.
     */
    public function createBrandConnectCustomer(Professional $brand): string
    {
        if (! $brand->stripe_connect_account_id) {
            throw new \RuntimeException('Brand must complete Stripe Connect onboarding before adding a payment method.');
        }

        $customer = $this->stripe->customers->create(
            [
                'email' => $brand->primary_email,
                'name' => $brand->display_name,
                'metadata' => [
                    'sidest_professional_id' => $brand->id,
                    'professional_type' => $brand->professional_type,
                    'purpose' => 'brand_commission_funding',
                ],
            ],
            [
                'stripe_account' => $brand->stripe_connect_account_id,
                'idempotency_key' => "brand_connect_customer_{$brand->id}",
            ],
        );

        $brand->update([
            'stripe_connect_customer_id' => $customer->id,
        ]);

        return $customer->id;
    }

    /**
     * Stripe Checkout hosted setup flow scoped to the BRAND'S OWN Connect
     * account. The resulting Customer + PaymentMethod live on the brand's
     * account, where the commission direct charge will later read them.
     *
     * The `stripe_account` request option (SDK form of the `Stripe-Account`
     * HTTP header) is what makes Stripe create the session on the brand's
     * account instead of Partna's platform.
     */
    public function createBrandConnectPaymentMethodSetupSession(
        Professional $brand,
        string $successUrl,
        string $cancelUrl,
    ): array {
        if (! $brand->stripe_connect_account_id) {
            throw new \RuntimeException('Brand must complete Stripe Connect onboarding before adding a payment method.');
        }

        $customerId = $brand->stripe_connect_customer_id;
        if (! $customerId) {
            $customerId = $this->createBrandConnectCustomer($brand);
        }

        $session = $this->stripe->checkout->sessions->create(
            [
                'mode' => 'setup',
                'customer' => $customerId,
                'payment_method_types' => ['card'],
                'success_url' => $this->appendCheckoutSessionParam($successUrl, 'stripe_pm_session_id'),
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'purpose' => 'brand_commission_payment_method',
                    'sidest_professional_id' => $brand->id,
                ],
            ],
            ['stripe_account' => $brand->stripe_connect_account_id],
        );

        return [
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    /**
     * Sync the brand's saved card from a completed Checkout setup session that
     * ran on the BRAND'S OWN Connect account. Mirrors
     * syncPaymentMethodFromCheckoutSession but every Stripe API call carries
     * `stripe_account = brand_connect_account_id` so the lookups hit the right
     * account, and the IDs land in the brand-Connect-scoped columns.
     *
     * @return array{payment_method_id: string, setup_intent_id: string}
     */
    public function syncBrandConnectPaymentMethodFromCheckoutSession(Professional $brand, string $sessionId): array
    {
        if (! $brand->stripe_connect_account_id) {
            throw new \RuntimeException('Brand has no Stripe Connect account.');
        }

        $stripeAccountOption = ['stripe_account' => $brand->stripe_connect_account_id];

        $session = $this->stripe->checkout->sessions->retrieve(
            $sessionId,
            ['expand' => ['setup_intent']],
            $stripeAccountOption,
        );

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

        if ($brand->stripe_connect_customer_id && $sessionCustomerId && $brand->stripe_connect_customer_id !== $sessionCustomerId) {
            throw new \RuntimeException('Setup session customer does not match account customer.');
        }

        if (! $brand->stripe_connect_customer_id && $sessionCustomerId) {
            $brand->update(['stripe_connect_customer_id' => $sessionCustomerId]);
            $brand->refresh();
        }

        $setupIntentId = is_string($session->setup_intent)
            ? $session->setup_intent
            : ($session->setup_intent->id ?? null);

        if (! $setupIntentId) {
            throw new \RuntimeException('Setup session missing setup intent.');
        }

        $setupIntent = $this->stripe->setupIntents->retrieve(
            $setupIntentId,
            ['expand' => ['payment_method']],
            $stripeAccountOption,
        );

        if (($setupIntent->status ?? null) !== 'succeeded') {
            throw new \RuntimeException('Setup intent has not succeeded.');
        }

        $paymentMethodId = is_string($setupIntent->payment_method)
            ? $setupIntent->payment_method
            : ($setupIntent->payment_method->id ?? null);

        if (! $paymentMethodId) {
            throw new \RuntimeException('No payment method found on setup intent.');
        }

        $this->saveBrandConnectPaymentMethod($brand, $paymentMethodId);

        return [
            'payment_method_id' => $paymentMethodId,
            'setup_intent_id' => $setupIntentId,
        ];
    }

    /**
     * Save the brand's default payment method on the BRAND'S OWN Connect
     * account. Reads the PM with `stripe_account = brand_connect_id` and
     * persists the IDs to the brand-Connect-scoped columns.
     */
    public function saveBrandConnectPaymentMethod(Professional $brand, string $paymentMethodId): void
    {
        if (! $brand->stripe_connect_account_id) {
            throw new \RuntimeException('Brand has no Stripe Connect account.');
        }

        $stripeAccountOption = ['stripe_account' => $brand->stripe_connect_account_id];

        $pm = $this->stripe->paymentMethods->retrieve($paymentMethodId, null, $stripeAccountOption);

        $brand->update([
            'stripe_connect_payment_method_id' => $paymentMethodId,
            'stripe_payment_method_brand' => $pm->card?->brand ?? null,
            'stripe_payment_method_last4' => $pm->card?->last4 ?? null,
        ]);

        if ($brand->stripe_connect_customer_id) {
            $this->stripe->customers->update(
                $brand->stripe_connect_customer_id,
                ['invoice_settings' => ['default_payment_method' => $paymentMethodId]],
                $stripeAccountOption,
            );
        }
    }

    /**
     * Check if a brand has a valid payment method for commission direct charges.
     * Reads the brand-Connect-scoped columns (the platform-scoped columns are
     * for SaaS billing, not commissions).
     */
    public function brandHasPaymentMethod(Professional $brand): bool
    {
        return $brand->stripe_connect_customer_id !== null
            && $brand->stripe_connect_payment_method_id !== null;
    }

    /**
     * List a brand's saved payment methods on their Connect account.
     */
    public function listPaymentMethods(Professional $brand): array
    {
        if (! $brand->stripe_connect_account_id || ! $brand->stripe_connect_customer_id) {
            return [];
        }

        $methods = $this->stripe->paymentMethods->all(
            [
                'customer' => $brand->stripe_connect_customer_id,
                'type' => 'card',
            ],
            ['stripe_account' => $brand->stripe_connect_account_id],
        );

        return array_map(fn ($m) => [
            'id' => $m->id,
            'brand' => $m->card?->brand,
            'last4' => $m->card?->last4,
            'exp_month' => $m->card?->exp_month,
            'exp_year' => $m->card?->exp_year,
            'is_default' => $m->id === $brand->stripe_connect_payment_method_id,
        ], $methods->data);
    }

    /**
     * Remove a brand's commission-payment setup. Clears the brand-Connect-scoped
     * columns only — the platform-scoped columns belong to SaaS billing and are
     * managed separately.
     */
    public function removeBrandPaymentSetup(Professional $brand): void
    {
        $brand->update([
            'stripe_connect_customer_id' => null,
            'stripe_connect_payment_method_id' => null,
            'stripe_payment_method_brand' => null,
            'stripe_payment_method_last4' => null,
        ]);
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

    /**
     * Resolve the shop currency from the professional's Shopify integration, if present.
     * Returns null when no Shopify integration exists or shop_currency is not yet set.
     */
    private function resolveShopCurrency(Professional $professional): ?string
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return null;
        }

        $currency = Arr::get($integration->provider_metadata ?? [], 'shop_currency');

        return ($currency && is_string($currency)) ? strtoupper(trim($currency)) : null;
    }

    /**
     * Stripe Connect supported countries as of 2026. Source:
     * https://docs.stripe.com/connect/cross-border-payouts#supported-countries-and-currencies
     * Update when Stripe expands the list.
     *
     * @var array<int, string>
     */
    private const STRIPE_CONNECT_SUPPORTED_COUNTRIES = [
        'AE', 'AT', 'AU', 'BE', 'BG', 'BR', 'CA', 'CH', 'CI', 'CR', 'CY', 'CZ', 'DE', 'DK', 'EE',
        'ES', 'FI', 'FR', 'GB', 'GH', 'GI', 'GR', 'HK', 'HR', 'HU', 'ID', 'IE', 'IN', 'IT', 'JP',
        'KE', 'KR', 'LI', 'LT', 'LU', 'LV', 'MT', 'MX', 'MY', 'NG', 'NL', 'NO', 'NZ', 'PE', 'PH',
        'PL', 'PT', 'RO', 'SA', 'SE', 'SG', 'SI', 'SK', 'TH', 'TR', 'US', 'UY', 'ZA',
    ];

    private function mapCountryCode(?string $code): string
    {
        $upper = strtoupper(trim((string) $code));

        if ($upper === '') {
            abort(422, 'A country is required for Stripe Connect onboarding. Please set your country on your profile before connecting.');
        }

        if (! in_array($upper, self::STRIPE_CONNECT_SUPPORTED_COUNTRIES, true)) {
            abort(422, "Country '{$upper}' is not supported by Stripe Connect. Please contact support.");
        }

        return $upper;
    }

    /**
     * Build the Stripe Account prefill block from a Professional row.
     *
     * Stripe pre-populates the Express onboarding form from any business_profile
     * and individual/company fields we pass on create. Missing fields are silently
     * dropped (via array_filter) so partial data is safe. Fields stay editable
     * for the user; we're saving them keystrokes, not locking values.
     *
     * Notes:
     * - business_profile.product_description is only sent when there's no URL.
     *   Stripe asks for one or the other; sending both is noisy.
     * - phone is only sent when it looks like E.164 (starts with '+').
     *   Free-form phone numbers cause Stripe to reject the entire create call.
     * - Address is only sent when line1 AND country are both present. Stripe
     *   rejects partial addresses without those two fields together.
     * - For brands we emit `company` (display_name, phone, address); for
     *   affiliates we emit `individual` (first/last name, email, phone, address).
     *
     * @return array<string, mixed>
     */
    private function buildAccountPrefillPayload(Professional $professional): array
    {
        $payload = [];

        $url = is_string($professional->partna_url ?? null) && trim($professional->partna_url) !== ''
            ? $professional->partna_url
            : null;

        $businessProfile = array_filter([
            'name' => $this->stringOrNull($professional->display_name),
            'url' => $url,
            // Only fall back to description when no URL is set (Stripe asks for one).
            'product_description' => $url === null ? $this->stringOrNull($professional->bio) : null,
            'support_email' => $this->stringOrNull($professional->primary_email),
        ]);

        if ($businessProfile !== []) {
            $payload['business_profile'] = $businessProfile;
        }

        $address = $this->buildAddressOrNull($professional);

        if ($professional->isBrand()) {
            $company = array_filter([
                'name' => $this->stringOrNull($professional->display_name),
                'phone' => $this->e164PhoneOrNull($professional->phone),
            ]);
            if ($address !== null) {
                $company['address'] = $address;
            }
            if ($company !== []) {
                $payload['company'] = $company;
            }

            return $payload;
        }

        $individual = array_filter([
            'first_name' => $this->stringOrNull($professional->first_name),
            'last_name' => $this->stringOrNull($professional->last_name),
            'email' => $this->stringOrNull($professional->primary_email),
            'phone' => $this->e164PhoneOrNull($professional->phone),
        ]);

        if ($address !== null) {
            $individual['address'] = $address;
        }

        if ($individual !== []) {
            $payload['individual'] = $individual;
        }

        return $payload;
    }

    /**
     * Build a Stripe address block from location_* fields, or null when the
     * required minimum (line1 + country) isn't present.
     *
     * @return array<string, string>|null
     */
    private function buildAddressOrNull(Professional $professional): ?array
    {
        $line1 = $this->stringOrNull($professional->location_street_address);
        $country = $this->stringOrNull($professional->location_country);

        if ($line1 === null || $country === null) {
            return null;
        }

        return array_filter([
            'line1' => $line1,
            'city' => $this->stringOrNull($professional->location_city),
            'state' => $this->stringOrNull($professional->location_state),
            'postal_code' => $this->stringOrNull($professional->location_postcode),
            'country' => $this->mapCountryCode($country),
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Stripe rejects the entire account create when phone isn't E.164. Drop
     * anything that doesn't start with '+' rather than risk a 400.
     */
    private function e164PhoneOrNull(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if ($value === null) {
            return null;
        }

        return str_starts_with($value, '+') ? $value : null;
    }
}
