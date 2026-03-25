<?php

namespace App\Services\Store;

use App\Models\Core\Professional\Professional;
use App\Models\Retail\BrandStoreSettings;
use App\Models\Retail\CheckoutSession;
use App\Models\Retail\CommissionLedgerEntry;
use App\Jobs\Stripe\ProcessCommissionPayoutsJob;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class PublicStripeCheckoutService
{
    private StripeClient $stripe;

    public function __construct(
        private readonly BrandProductCatalogService $catalog,
        private readonly ShopifyOrderCreationService $shopifyOrders,
    ) {
        $this->stripe = new StripeClient(config('services.stripe.secret_key'));
    }

    public function createHostedCheckoutSession(
        CheckoutSession $checkoutSession,
        array $customer,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $brand = $checkoutSession->brandProfessional()->first();
        if (! $brand instanceof Professional) {
            throw new \RuntimeException('Connected brand account was not found.');
        }

        $checkoutMode = $this->resolveCheckoutMode((string) $brand->id);
        if ($checkoutMode !== 'stripe') {
            throw new \RuntimeException('This brand is not using Comet Payments.');
        }

        if (
            ! $brand->stripe_connect_account_id
            || trim((string) $brand->stripe_connect_status) !== 'active'
        ) {
            throw new \RuntimeException('Brand Stripe account is not connected.');
        }

        $contextSnapshot = is_array($checkoutSession->context_snapshot) ? $checkoutSession->context_snapshot : [];
        $normalizedLineItems = $this->normalizeLineItems(
            (string) $checkoutSession->affiliate_professional_id,
            (string) $checkoutSession->brand_professional_id,
            Arr::get($contextSnapshot, 'line_items', [])
        );

        if ($normalizedLineItems === []) {
            throw new \RuntimeException('No valid storefront items were supplied for checkout.');
        }

        $currencyCode = strtoupper(trim((string) ($contextSnapshot['currency_code'] ?? 'AUD')));
        if ($currencyCode === '') {
            $currencyCode = 'AUD';
        }

        $orderTotalCents = array_sum(array_column($normalizedLineItems, 'line_total_cents'));
        $grossCommissionCents = array_sum(array_column($normalizedLineItems, 'commission_cents'));
        if ($orderTotalCents <= 0) {
            throw new \RuntimeException('Checkout total must be greater than zero.');
        }

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'success_url' => $this->appendSessionIdPlaceholder($successUrl, 'stripe_store_session_id'),
            'cancel_url' => $cancelUrl,
            'customer_email' => strtolower(trim((string) ($customer['email'] ?? ''))),
            'line_items' => array_map(
                static fn (array $lineItem): array => [
                    'quantity' => $lineItem['quantity'],
                    'price_data' => [
                        'currency' => strtolower($lineItem['currency_code']),
                        'unit_amount' => $lineItem['unit_price_cents'],
                        'product_data' => [
                            'name' => $lineItem['title'],
                        ],
                    ],
                ],
                $normalizedLineItems
            ),
            'metadata' => [
                'purpose' => 'public_store_stripe_checkout',
                'checkout_session_token' => $checkoutSession->token,
                'site_id' => (string) $checkoutSession->site_id,
                'affiliate_professional_id' => (string) $checkoutSession->affiliate_professional_id,
                'brand_professional_id' => (string) $checkoutSession->brand_professional_id,
            ],
            'payment_intent_data' => [
                'application_fee_amount' => $grossCommissionCents,
                'metadata' => [
                    'purpose' => 'public_store_stripe_checkout',
                    'checkout_session_token' => $checkoutSession->token,
                    'site_id' => (string) $checkoutSession->site_id,
                    'affiliate_professional_id' => (string) $checkoutSession->affiliate_professional_id,
                    'brand_professional_id' => (string) $checkoutSession->brand_professional_id,
                    'gross_commission_cents' => (string) $grossCommissionCents,
                    'order_total_cents' => (string) $orderTotalCents,
                ],
            ],
        ], [
            'stripe_account' => $brand->stripe_connect_account_id,
        ]);

        $checkoutSession->context_snapshot = array_merge($contextSnapshot, [
            'checkout_mode' => 'stripe',
            'customer' => $this->normalizeCustomerSnapshot($customer),
            'line_items' => $normalizedLineItems,
            'stripe' => array_merge(
                is_array($contextSnapshot['stripe'] ?? null) ? $contextSnapshot['stripe'] : [],
                [
                    'checkout_session_id' => $session->id,
                    'payment_intent_id' => is_string($session->payment_intent) ? $session->payment_intent : null,
                    'gross_commission_cents' => $grossCommissionCents,
                    'order_total_cents' => $orderTotalCents,
                    'created_at' => now()->toIso8601String(),
                ]
            ),
        ]);
        $checkoutSession->save();

        return [
            'checkout_url' => $session->url,
            'session_id' => $session->id,
            'currency_code' => $currencyCode,
            'gross_commission_cents' => $grossCommissionCents,
            'order_total_cents' => $orderTotalCents,
        ];
    }

    /**
     * Create a PaymentIntent on the brand's connected Stripe account for an embedded card form.
     * Returns client_secret + brand_stripe_account_id for the frontend to confirm with.
     */
    public function createPaymentIntent(
        CheckoutSession $checkoutSession,
        array $customer,
    ): array {
        $brand = $checkoutSession->brandProfessional()->first();
        if (! $brand instanceof Professional) {
            throw new \RuntimeException('Connected brand account was not found.');
        }

        $checkoutMode = $this->resolveCheckoutMode((string) $brand->id);
        if ($checkoutMode !== 'stripe') {
            throw new \RuntimeException('This brand is not using Comet Payments.');
        }

        if (
            ! $brand->stripe_connect_account_id
            || trim((string) $brand->stripe_connect_status) !== 'active'
        ) {
            throw new \RuntimeException('Brand Stripe account is not connected.');
        }

        $contextSnapshot = is_array($checkoutSession->context_snapshot) ? $checkoutSession->context_snapshot : [];
        $normalizedLineItems = $this->normalizeLineItems(
            (string) $checkoutSession->affiliate_professional_id,
            (string) $checkoutSession->brand_professional_id,
            Arr::get($contextSnapshot, 'line_items', [])
        );

        if ($normalizedLineItems === []) {
            throw new \RuntimeException('No valid storefront items were supplied for checkout.');
        }

        $currencyCode = strtoupper(trim((string) ($contextSnapshot['currency_code'] ?? 'AUD')));
        if ($currencyCode === '') {
            $currencyCode = 'AUD';
        }

        $orderTotalCents = array_sum(array_column($normalizedLineItems, 'line_total_cents'));
        $grossCommissionCents = array_sum(array_column($normalizedLineItems, 'commission_cents'));

        if ($orderTotalCents <= 0) {
            throw new \RuntimeException('Checkout total must be greater than zero.');
        }

        $paymentIntent = $this->stripe->paymentIntents->create([
            'amount' => $orderTotalCents,
            'currency' => strtolower($currencyCode),
            'payment_method_types' => ['card'],
            'application_fee_amount' => $grossCommissionCents,
            'metadata' => [
                'purpose' => 'public_store_payment',
                'checkout_session_token' => $checkoutSession->token,
                'site_id' => (string) $checkoutSession->site_id,
                'affiliate_professional_id' => (string) $checkoutSession->affiliate_professional_id,
                'brand_professional_id' => (string) $brand->id,
                'gross_commission_cents' => (string) $grossCommissionCents,
                'order_total_cents' => (string) $orderTotalCents,
            ],
        ], ['stripe_account' => $brand->stripe_connect_account_id]);

        $checkoutSession->context_snapshot = array_merge($contextSnapshot, [
            'checkout_mode' => 'stripe',
            'customer' => $this->normalizeCustomerSnapshot($customer),
            'line_items' => $normalizedLineItems,
            'stripe' => array_merge(
                is_array($contextSnapshot['stripe'] ?? null) ? $contextSnapshot['stripe'] : [],
                [
                    'payment_intent_id' => $paymentIntent->id,
                    'gross_commission_cents' => $grossCommissionCents,
                    'order_total_cents' => $orderTotalCents,
                    'created_at' => now()->toIso8601String(),
                ]
            ),
        ]);
        $checkoutSession->save();

        return [
            'client_secret' => $paymentIntent->client_secret,
            'brand_stripe_account_id' => $brand->stripe_connect_account_id,
            'amount' => $orderTotalCents,
            'currency' => strtolower($currencyCode),
        ];
    }

    /**
     * Finalize a storefront order after payment_intent.succeeded webhook fires.
     */
    public function finalizePaymentIntentOrder(object $paymentIntent, ?string $connectedAccountId = null): void
    {
        $token = trim((string) ($paymentIntent->metadata?->checkout_session_token ?? ''));
        if ($token === '') {
            return;
        }

        DB::transaction(function () use ($token, $paymentIntent, $connectedAccountId): void {
            $checkoutSession = CheckoutSession::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if (! $checkoutSession) {
                return;
            }

            $contextSnapshot = is_array($checkoutSession->context_snapshot) ? $checkoutSession->context_snapshot : [];
            $existingStripe = is_array($contextSnapshot['stripe'] ?? null) ? $contextSnapshot['stripe'] : [];

            // Idempotency guard
            if (trim((string) ($existingStripe['shopify_order_id'] ?? '')) !== '') {
                return;
            }

            $brand = $checkoutSession->brandProfessional()->first();
            if (! $brand instanceof Professional || ! $brand->stripe_connect_account_id) {
                throw new \RuntimeException('Unable to resolve connected brand for payment intent finalization.');
            }

            if ($connectedAccountId !== null && $connectedAccountId !== '' && $brand->stripe_connect_account_id !== $connectedAccountId) {
                throw new \RuntimeException('Stripe connected account mismatch during payment intent finalization.');
            }

            $orderResult = $this->shopifyOrders->createPaidOrderFromCheckoutSession(
                $checkoutSession,
                $brand,
                $contextSnapshot,
                [
                    'stripe_payment_intent_id' => (string) $paymentIntent->id,
                ]
            );

            $checkoutSession->context_snapshot = array_merge($contextSnapshot, [
                'stripe' => array_merge($existingStripe, [
                    'payment_intent_id' => (string) $paymentIntent->id,
                    'payment_completed_at' => now()->toIso8601String(),
                    'shopify_order_id' => $orderResult['order_id'] ?? null,
                    'shopify_order_name' => $orderResult['order_name'] ?? null,
                    'shopify_draft_order_id' => $orderResult['draft_order_id'] ?? null,
                ]),
            ]);
            $checkoutSession->last_seen_at = now();
            $checkoutSession->save();

            // Create commission ledger entries directly from the Stripe payment data.
            $this->createCommissionLedgerEntries($checkoutSession, $contextSnapshot, $paymentIntent);
        });
    }

    /**
     * Create commission ledger entries for each line item from a Stripe storefront payment.
     */
    private function createCommissionLedgerEntries(
        CheckoutSession $checkoutSession,
        array $contextSnapshot,
        object $paymentIntent,
    ): void {
        $lineItems = Arr::get($contextSnapshot, 'line_items', []);
        if (! is_array($lineItems) || $lineItems === []) {
            return;
        }

        $brandProfessionalId = (string) $checkoutSession->brand_professional_id;
        $affiliateProfessionalId = (string) $checkoutSession->affiliate_professional_id;
        $paymentIntentId = (string) $paymentIntent->id;

        foreach ($lineItems as $index => $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $commissionCents = (int) ($lineItem['commission_cents'] ?? 0);
            if ($commissionCents <= 0) {
                continue;
            }

            $idempotencyKey = "stripe_pi:{$paymentIntentId}:item:{$index}";

            CommissionLedgerEntry::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'brand_professional_id' => $brandProfessionalId,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'entry_type' => 'accrual',
                    'status' => 'approved',
                    'amount_cents' => $commissionCents,
                    'currency_code' => strtoupper((string) ($lineItem['currency_code'] ?? 'AUD')),
                    'commission_rate' => (float) ($lineItem['commission_rate'] ?? 0),
                    'rate_source' => 'stripe_storefront',
                    'idempotency_key' => $idempotencyKey,
                    'calculation_metadata' => [
                        'payment_intent_id' => $paymentIntentId,
                        'checkout_session_token' => (string) $checkoutSession->token,
                        'brand_product_id' => (string) ($lineItem['brand_product_id'] ?? ''),
                        'shopify_product_id' => (string) ($lineItem['shopify_product_id'] ?? ''),
                        'line_total_cents' => (int) ($lineItem['line_total_cents'] ?? 0),
                        'unit_price_cents' => (int) ($lineItem['unit_price_cents'] ?? 0),
                        'quantity' => (int) ($lineItem['quantity'] ?? 1),
                        'funding_source' => 'stripe_sale_hold',
                    ],
                    'occurred_at' => now(),
                ]
            );
        }

        Log::info('Commission ledger entries created from Stripe payment.', [
            'payment_intent_id' => $paymentIntentId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'brand_professional_id' => $brandProfessionalId,
            'line_items_count' => count($lineItems),
        ]);

        // Dispatch payout processing so commissions are transferred promptly.
        ProcessCommissionPayoutsJob::dispatch();
    }

    public function finalizeCompletedCheckoutSession(object $session, ?string $connectedAccountId = null): void
    {
        $sessionId = trim((string) ($session->id ?? ''));
        $token = trim((string) ($session->metadata->checkout_session_token ?? ''));
        if ($sessionId === '' || $token === '') {
            return;
        }

        DB::transaction(function () use ($sessionId, $token, $session, $connectedAccountId): void {
            $checkoutSession = CheckoutSession::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if (! $checkoutSession) {
                return;
            }

            $contextSnapshot = is_array($checkoutSession->context_snapshot) ? $checkoutSession->context_snapshot : [];
            $existingStripe = is_array($contextSnapshot['stripe'] ?? null) ? $contextSnapshot['stripe'] : [];
            $existingOrderId = trim((string) ($existingStripe['shopify_order_id'] ?? ''));
            if ($existingOrderId !== '') {
                return;
            }

            $brand = $checkoutSession->brandProfessional()->first();
            if (! $brand instanceof Professional || ! $brand->stripe_connect_account_id) {
                throw new \RuntimeException('Unable to resolve connected brand for Stripe checkout finalization.');
            }

            if ($connectedAccountId !== null && $connectedAccountId !== '' && $brand->stripe_connect_account_id !== $connectedAccountId) {
                throw new \RuntimeException('Stripe connected account mismatch during checkout finalization.');
            }

            $paymentIntentId = is_string($session->payment_intent ?? null)
                ? $session->payment_intent
                : ($session->payment_intent->id ?? null);

            $orderResult = $this->shopifyOrders->createPaidOrderFromCheckoutSession(
                $checkoutSession,
                $brand,
                $contextSnapshot,
                [
                    'stripe_checkout_session_id' => $sessionId,
                    'stripe_payment_intent_id' => is_string($paymentIntentId) ? $paymentIntentId : '',
                ]
            );

            $checkoutSession->context_snapshot = array_merge($contextSnapshot, [
                'stripe' => array_merge($existingStripe, [
                    'checkout_session_id' => $sessionId,
                    'payment_intent_id' => is_string($paymentIntentId) ? $paymentIntentId : null,
                    'checkout_completed_at' => now()->toIso8601String(),
                    'shopify_order_id' => $orderResult['order_id'] ?? null,
                    'shopify_order_name' => $orderResult['order_name'] ?? null,
                    'shopify_draft_order_id' => $orderResult['draft_order_id'] ?? null,
                ]),
            ]);
            $checkoutSession->last_seen_at = now();
            $checkoutSession->save();
        });
    }

    private function resolveCheckoutMode(string $brandProfessionalId): string
    {
        $mode = BrandStoreSettings::query()
            ->where('professional_id', $brandProfessionalId)
            ->value('checkout_mode');

        $mode = trim((string) $mode);
        return in_array($mode, ['shopify', 'stripe'], true) ? $mode : 'shopify';
    }

    private function normalizeLineItems(string $affiliateProfessionalId, string $brandProfessionalId, mixed $rawLineItems): array
    {
        if (! is_array($rawLineItems)) {
            return [];
        }

        $catalogRows = $this->catalog->selectedProductsForProfessional($affiliateProfessionalId);
        $productsByShopifyId = [];
        foreach ($catalogRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['brand_professional_id'] ?? '') !== $brandProfessionalId) {
                continue;
            }
            foreach ($this->shopifyIdKeys((string) ($row['shopify_product_id'] ?? '')) as $key) {
                $productsByShopifyId[$key] = $row;
            }
        }

        $normalized = [];
        foreach ($rawLineItems as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }

            $shopifyProductId = trim((string) ($lineItem['shopify_product_id'] ?? ''));
            $shopifyVariantId = trim((string) ($lineItem['shopify_variant_id'] ?? ''));
            $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));
            if ($shopifyProductId === '' || $shopifyVariantId === '') {
                continue;
            }

            $catalogRow = $this->resolveCatalogRow($productsByShopifyId, $shopifyProductId);
            if (! $catalogRow) {
                continue;
            }

            $unitPriceCents = max(
                0,
                (int) ($lineItem['unit_price_cents'] ?? ($catalogRow['discounted_price_cents'] ?? $catalogRow['base_price_cents'] ?? 0))
            );
            $lineTotalCents = max(
                0,
                (int) ($lineItem['line_total_cents'] ?? ($unitPriceCents * $quantity))
            );
            $commissionRate = (float) ($catalogRow['effective_commission_rate'] ?? config('comet.store.default_commission_rate', 15));

            $normalized[] = [
                'brand_product_id' => (string) ($catalogRow['brand_product_id'] ?? ''),
                'shopify_product_id' => $shopifyProductId,
                'shopify_variant_id' => $shopifyVariantId,
                'title' => trim((string) ($lineItem['title'] ?? $catalogRow['title'] ?? 'Product')),
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'line_total_cents' => $lineTotalCents,
                'currency_code' => strtoupper((string) ($lineItem['currency_code'] ?? $catalogRow['currency_code'] ?? 'AUD')),
                'commission_rate' => $commissionRate,
                'commission_cents' => (int) round(($lineTotalCents * $commissionRate) / 100, 0, PHP_ROUND_HALF_UP),
            ];
        }

        return $normalized;
    }

    private function resolveCatalogRow(array $productsByShopifyId, string $shopifyProductId): ?array
    {
        foreach ($this->shopifyIdKeys($shopifyProductId) as $key) {
            if (isset($productsByShopifyId[$key]) && is_array($productsByShopifyId[$key])) {
                return $productsByShopifyId[$key];
            }
        }

        return null;
    }

    private function shopifyIdKeys(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $keys = [strtolower($value)];
        if (preg_match('/(\d+)(?!.*\d)/', $value, $matches) === 1) {
            $digits = $matches[1];
            $keys[] = $digits;
            $keys[] = strtolower('gid://shopify/Product/'.$digits);
        }

        return array_values(array_unique(array_filter($keys, static fn ($key): bool => is_string($key) && $key !== '')));
    }

    private function normalizeCustomerSnapshot(array $customer): array
    {
        return [
            'name' => trim((string) ($customer['name'] ?? '')),
            'email' => strtolower(trim((string) ($customer['email'] ?? ''))),
            'phone' => trim((string) ($customer['phone'] ?? '')),
            'address1' => trim((string) ($customer['address1'] ?? '')),
            'address2' => trim((string) ($customer['address2'] ?? '')),
            'company' => trim((string) ($customer['company'] ?? '')),
            'city' => trim((string) ($customer['city'] ?? '')),
            'province' => trim((string) ($customer['province'] ?? '')),
            'country' => trim((string) ($customer['country'] ?? '')),
            'zip' => trim((string) ($customer['zip'] ?? '')),
        ];
    }

    private function appendSessionIdPlaceholder(string $url, string $param): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url.$separator.$param.'={CHECKOUT_SESSION_ID}';
    }
}
