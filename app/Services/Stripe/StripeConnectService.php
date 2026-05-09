<?php

namespace App\Services\Stripe;

use App\Models\Commerce\WalletMovement;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\WalletCurrencySwitchAudit;
use App\Models\Retail\BrandCommissionTopup;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $update = [
            'stripe_connect_account_id' => $account->id,
            'stripe_connect_status' => 'onboarding',
            'stripe_grace_period_ends_at' => $professional->stripe_grace_period_ends_at
                ?? now()->addDays((int) config('partna.store.grace_period_days', 30)),
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
        $pm = $this->stripe->paymentMethods->retrieve($paymentMethodId);

        $brand->update([
            'stripe_payment_method_id'    => $paymentMethodId,
            'stripe_payment_method_brand' => $pm->card?->brand ?? null,
            'stripe_payment_method_last4' => $pm->card?->last4 ?? null,
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
                'purpose'                => 'brand_commission_topup',
                'professional_id'        => $brand->id,   // read by webhook handler
                'sidest_professional_id' => $brand->id,   // kept for backward compat
                'currency'               => $currency,
            ],
        ]);

        return [
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    /**
     * Credit the brand's wallet from a completed Stripe Checkout session.
     * Race-safe (lockForUpdate), idempotent (UNIQUE idempotency_key), AUSTRAC-tagged.
     *
     * Called from: (1) confirmManualTopUpCheckoutSession (user hits success URL),
     *              (2) handleCheckoutSessionCompleted webhook handler.
     * Pass _actor_override on the session object to tag the WalletMovement actor_type
     * as 'professional' instead of the default 'webhook'.
     */
    public function creditWalletFromCheckoutSession(string $professionalId, object $session): void
    {
        if (($session->payment_status ?? null) !== 'paid') {
            Log::warning('stripe.topup.unpaid_session', ['session_id' => $session->id ?? null]);

            return;
        }

        $amountCents = (int) ($session->amount_total ?? 0);
        if ($amountCents <= 0) {
            return;
        }

        $sessionId = $session->id;
        $currency = strtoupper($session->currency ?? 'AUD');
        $stripeEventId = $session->_stripe_event_id ?? null;
        $actorOverride = $session->_actor_override ?? null;

        DB::transaction(function () use ($professionalId, $session, $sessionId, $amountCents, $currency, $stripeEventId, $actorOverride) {
            $brand = Professional::query()
                ->where('id', $professionalId)
                ->lockForUpdate()
                ->first();

            if (! $brand) {
                Log::warning('stripe.topup.brand_not_found', ['professional_id' => $professionalId]);

                return;
            }

            // Currency mismatch → auto-refund + alert (do not credit the wallet).
            $walletCurrency = strtoupper($brand->stripe_manual_balance_currency ?? 'AUD');
            if ($walletCurrency !== $currency) {
                Log::error('stripe.topup.currency_mismatch', [
                    'professional_id'  => $professionalId,
                    'wallet_currency'  => $walletCurrency,
                    'session_currency' => $currency,
                    'amount_cents'     => $amountCents,
                ]);

                if (! empty($session->payment_intent)) {
                    try {
                        $this->stripe->refunds->create(
                            [
                                'payment_intent' => is_string($session->payment_intent)
                                    ? $session->payment_intent
                                    : ($session->payment_intent->id ?? null),
                                'reason'   => 'requested_by_customer',
                                'metadata' => [
                                    'sidest_reason'   => 'currency_mismatch',
                                    'professional_id' => $professionalId,
                                ],
                            ],
                            ['idempotency_key' => 'currency_mismatch_refund:'.$sessionId],
                        );
                    } catch (ApiErrorException $e) {
                        Log::critical('stripe.topup.currency_mismatch_refund_failed', [
                            'session_id' => $sessionId,
                            'error'      => $e->getMessage(),
                        ]);
                        report($e);
                    }
                }

                return;
            }

            $idempotencyKey = 'topup:'.$sessionId;
            $actor = $actorOverride ?? [
                'type' => 'webhook',
                'id'   => $stripeEventId ?? ('checkout.session.completed:'.$sessionId),
            ];

            // UNIQUE(idempotency_key) provides idempotency — a duplicate delivery throws
            // QueryException which we catch and swallow; the balance is already correct.
            // WalletMovement uses $guarded = ['*'] so we must use forceFill() on an instance.
            try {
                (new WalletMovement)->forceFill([
                    'professional_id'    => $brand->id,
                    'direction'          => 'credit',
                    'amount_cents'       => $amountCents,
                    'currency_code'      => $currency,
                    'reason'             => 'top_up',
                    'actor_type'         => $actor['type'],
                    'actor_id'           => $actor['id'],
                    'related_session_id' => $sessionId,
                    'idempotency_key'    => $idempotencyKey,
                    'metadata'           => [
                        'session_id'             => $sessionId,
                        'session_payment_intent' => is_string($session->payment_intent ?? null)
                            ? $session->payment_intent
                            : null,
                    ],
                ])->save();
            } catch (\Illuminate\Database\QueryException $e) {
                // Unique constraint violation — duplicate delivery, already credited.
                if (str_contains($e->getMessage(), 'idempotency_key') || str_contains($e->getMessage(), 'UNIQUE')) {
                    Log::info('stripe.topup.duplicate_session', ['session_id' => $sessionId]);

                    return;
                }

                throw $e;
            }

            // Apply to balance atomically; row is locked above via lockForUpdate.
            Professional::where('id', $brand->id)
                ->update(['stripe_manual_balance_currency' => $currency]);
            Professional::where('id', $brand->id)
                ->increment('stripe_manual_balance_cents', $amountCents);

            Log::info('stripe.topup.credited', [
                'professional_id' => $brand->id,
                'session_id'      => $sessionId,
                'amount_cents'    => $amountCents,
            ]);
        });
    }

    /**
     * Confirm a completed top-up Checkout session and credit the brand balance.
     * Delegates to creditWalletFromCheckoutSession — idempotent via wallet_movements UNIQUE key.
     */
    public function confirmManualTopUpCheckoutSession(Professional $brand, string $sessionId): array
    {
        $session = $this->stripe->checkout->sessions->retrieve($sessionId, ['expand' => ['payment_intent']]);

        if (($session->mode ?? null) !== 'payment') {
            throw new \RuntimeException('Checkout session is not a top-up payment session.');
        }

        if (($session->payment_status ?? null) !== 'paid') {
            throw new \RuntimeException('Top-up payment is not completed yet.');
        }

        // Check ownership via both metadata keys for backward compat with sessions
        // created before the professional_id key was added.
        $metaProId = $session->metadata?->professional_id
            ?? $session->metadata?->sidest_professional_id
            ?? null;
        if ($metaProId && $metaProId !== $brand->id) {
            throw new \RuntimeException('Top-up session does not belong to this account.');
        }

        $purpose = $session->metadata?->purpose ?? null;
        if ($purpose !== 'brand_commission_topup') {
            throw new \RuntimeException('Invalid top-up session purpose.');
        }

        // Tag this code path so the WalletMovement records actor_type='professional'
        // (user hitting success URL) rather than 'webhook' (Stripe firing the event).
        $session->_actor_override = ['type' => 'professional', 'id' => (string) $brand->id];

        // Idempotent — if the webhook already fired, the UNIQUE idempotency_key
        // constraint absorbs the duplicate and the balance is unchanged.
        $this->creditWalletFromCheckoutSession((string) $brand->id, $session);

        $brand->refresh();

        return [
            'session_id'    => $sessionId,
            'amount_cents'  => (int) ($session->amount_total ?? 0),
            'balance_cents' => (int) ($brand->stripe_manual_balance_cents ?? 0),
            'status'        => 'credited',
        ];
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

    private function mapCountryCode(?string $code): string
    {
        if (! $code) {
            return 'AU';
        }

        return strtoupper($code);
    }
}
