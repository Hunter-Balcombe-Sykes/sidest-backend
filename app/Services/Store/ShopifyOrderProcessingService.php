<?php

namespace App\Services\Store;

use App\Models\Retail\BrandProduct;
use App\Models\Retail\CheckoutSession;
use App\Models\Retail\CommissionLedgerEntry;
use App\Models\Retail\OrderEventInbox;
use App\Models\Retail\OrderItem;
use App\Models\Retail\RetailOrder;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyOrderProcessingService
{
    /**
     * @return array<string, mixed>
     */
    public function processInbox(string $inboxId): array
    {
        $inboxId = trim($inboxId);
        if ($inboxId === '') {
            return ['status' => 'missing'];
        }

        try {
            $result = DB::transaction(function () use ($inboxId): array {
                $inbox = OrderEventInbox::query()
                    ->where('id', $inboxId)
                    ->lockForUpdate()
                    ->first();

                if (! $inbox) {
                    return ['status' => 'missing'];
                }

                if (in_array((string) $inbox->status, ['processed', 'rejected'], true)) {
                    return [
                        'status' => (string) $inbox->status,
                        'inbox_id' => (string) $inbox->id,
                    ];
                }

                $inbox->status = 'processing';
                $inbox->attempts = (int) ($inbox->attempts ?? 0) + 1;
                $inbox->last_error = null;
                $inbox->save();

                $payload = is_array($inbox->payload) ? $inbox->payload : [];
                $shopifyOrderId = trim((string) ($payload['id'] ?? Arr::get($payload, 'admin_graphql_api_id', '')));
                $source = $this->resolveOrderSource($payload);

                if ($shopifyOrderId === '') {
                    return $this->rejectLockedInbox($inbox, 'MISSING_ORDER_ID');
                }

                $token = $this->extractCometSessionToken($payload);
                if ($token === null) {
                    return $this->rejectLockedInbox($inbox, 'MISSING_OR_INVALID_SESSION_TOKEN');
                }

                $checkoutSession = CheckoutSession::query()
                    ->where('token', $token)
                    ->first();

                if (! $checkoutSession) {
                    return $this->rejectLockedInbox($inbox, 'UNKNOWN_SESSION_TOKEN');
                }

                if (in_array((string) $checkoutSession->status, ['cancelled', 'expired'], true)) {
                    return $this->rejectLockedInbox($inbox, 'INACTIVE_SESSION_TOKEN');
                }

                if ($checkoutSession->expires_at instanceof Carbon && now()->gt($checkoutSession->expires_at)) {
                    if ((string) $checkoutSession->status === 'active') {
                        $checkoutSession->status = 'expired';
                        $checkoutSession->save();
                    }

                    return $this->rejectLockedInbox($inbox, 'EXPIRED_SESSION_TOKEN');
                }

                $sessionBrand = (string) $checkoutSession->brand_professional_id;
                $inboxBrand = trim((string) $inbox->brand_professional_id);
                if ($inboxBrand === '' || $sessionBrand !== $inboxBrand) {
                    return $this->rejectLockedInbox($inbox, 'CROSS_BRAND_SESSION_TOKEN');
                }

                $brandProfessionalId = $sessionBrand;
                $affiliateProfessionalId = (string) $checkoutSession->affiliate_professional_id;
                $currencyCode = strtoupper(trim((string) ($payload['currency'] ?? 'AUD')));
                if ($currencyCode === '') {
                    $currencyCode = 'AUD';
                }

                $grossCents = $this->toCents(
                    Arr::get($payload, 'current_total_price_set.shop_money.amount', Arr::get($payload, 'total_price'))
                );
                $refundedCents = $this->toCents(
                    Arr::get($payload, 'current_total_refunds_set.shop_money.amount', Arr::get($payload, 'total_refunds'))
                );
                $returnedCents = 0;
                $netCents = max(0, $grossCents - $refundedCents - $returnedCents);

                $orderedAt = $this->nullableTimestamp((string) ($payload['created_at'] ?? '')) ?? now();
                $paidAt = $this->nullableTimestamp((string) ($payload['processed_at'] ?? ''));
                $cancelledAt = $this->nullableTimestamp((string) ($payload['cancelled_at'] ?? ''));
                $closedAt = $this->nullableTimestamp((string) ($payload['closed_at'] ?? ''));

                $customerEmail = strtolower(trim((string) (
                    $payload['email']
                    ?? Arr::get($payload, 'customer.email', '')
                )));

                $customerEmailHash = $customerEmail !== '' ? hash('sha256', $customerEmail) : null;
                $customerRegion = trim((string) (
                    Arr::get($payload, 'shipping_address.province_code')
                    ?? Arr::get($payload, 'shipping_address.country_code')
                    ?? Arr::get($payload, 'shipping_address.country')
                    ?? ''
                ));
                $shippingCountryCode = strtoupper(trim((string) Arr::get($payload, 'shipping_address.country_code', '')));

                $order = RetailOrder::query()->firstOrNew([
                    'shopify_order_id' => $shopifyOrderId,
                ]);

                $order->fill([
                    'order_name' => $this->nullableString((string) ($payload['name'] ?? '')),
                    'source' => $source,
                    'shop_domain' => strtolower(trim((string) ($inbox->shop_domain ?? ''))),
                    'brand_professional_id' => $brandProfessionalId,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'checkout_session_token' => $token,
                    'lifecycle_status' => $this->resolveLifecycleStatus($payload),
                    'financial_status' => $this->resolveFinancialStatus((string) ($payload['financial_status'] ?? '')),
                    'fulfillment_status' => $this->resolveFulfillmentStatus((string) ($payload['fulfillment_status'] ?? '')),
                    'currency_code' => $currencyCode,
                    'gross_cents' => $grossCents,
                    'refunded_cents' => $refundedCents,
                    'returned_cents' => $returnedCents,
                    'net_cents' => $netCents,
                    'ordered_at' => $orderedAt,
                    'paid_at' => $paidAt,
                    'cancelled_at' => $cancelledAt,
                    'closed_at' => $closedAt,
                    'customer_email_hash' => $customerEmailHash,
                    'customer_region' => $this->nullableString($customerRegion),
                    'shipping_country_code' => $this->nullableString($shippingCountryCode),
                    'raw_payload' => $payload,
                ]);
                $order->save();

                if ((string) $checkoutSession->status === 'active') {
                    $checkoutSession->status = 'converted';
                    $checkoutSession->converted_at = now();
                }
                $checkoutSession->last_seen_at = now();
                $checkoutSession->save();

                $lineItems = collect($payload['line_items'] ?? [])
                    ->filter(static fn ($value): bool => is_array($value))
                    ->values();

                $brandProductMap = $this->resolveBrandProductMap($brandProfessionalId);
                $refundsByLineItem = $this->refundsByLineItem($payload);

                // Resolve all brand product IDs upfront so commission rates can
                // be batch-loaded before the loop (3 queries total, not 3 per item).
                $brandProductIds = $lineItems->map(function (array $lineItem) use ($brandProductMap): ?string {
                    $shopifyProductRaw = $this->firstNonEmpty([
                        (string) ($lineItem['product_id'] ?? ''),
                        (string) Arr::get($lineItem, 'product.id', ''),
                        (string) Arr::get($lineItem, 'admin_graphql_api_id', ''),
                    ]);

                    return $this->matchBrandProductId($shopifyProductRaw, $brandProductMap);
                })->all();

                $commissionCache = $this->prefetchCommissionRates(
                    $brandProfessionalId,
                    $affiliateProfessionalId,
                    $brandProductIds
                );

                foreach ($lineItems as $index => $lineItem) {
                    $lineId = trim((string) ($lineItem['id'] ?? ''));
                    if ($lineId === '') {
                        $lineId = 'line_'.$index;
                    }

                    $shopifyProductRaw = $this->firstNonEmpty([
                        (string) ($lineItem['product_id'] ?? ''),
                        (string) Arr::get($lineItem, 'product.id', ''),
                        (string) Arr::get($lineItem, 'admin_graphql_api_id', ''),
                    ]);

                    $brandProductId = $this->matchBrandProductId($shopifyProductRaw, $brandProductMap);
                    $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));
                    $unitPriceCents = $this->toCents(
                        Arr::get($lineItem, 'price_set.shop_money.amount', Arr::get($lineItem, 'price'))
                    );

                    $grossLineCents = $this->toCents(Arr::get($lineItem, 'original_line_price_set.shop_money.amount'));
                    if ($grossLineCents <= 0) {
                        $grossLineCents = max(0, $unitPriceCents * $quantity);
                    }

                    $discountLineCents = $this->toCents(
                        Arr::get($lineItem, 'total_discount_set.shop_money.amount', Arr::get($lineItem, 'total_discount'))
                    );
                    $refundedLineCents = (int) ($refundsByLineItem[$lineId] ?? 0);
                    $returnedLineCents = 0;
                    $accrualBaseLineCents = max(0, $grossLineCents - $discountLineCents - $returnedLineCents);
                    $netLineCents = max(0, $accrualBaseLineCents - $refundedLineCents);

                    $lineCurrencyCode = strtoupper(trim((string) (
                        Arr::get($lineItem, 'price_set.shop_money.currency_code')
                        ?? Arr::get($lineItem, 'original_line_price_set.shop_money.currency_code')
                        ?? $currencyCode
                    )));
                    if ($lineCurrencyCode === '') {
                        $lineCurrencyCode = $currencyCode;
                    }

                    $normalizedShopifyProductId = $this->normalizeShopifyProductId($shopifyProductRaw);
                    $normalizedShopifyVariantId = $this->normalizeShopifyVariantId($lineItem);

                    $item = OrderItem::query()->updateOrCreate(
                        [
                            'order_id' => (string) $order->id,
                            'shopify_line_item_id' => $lineId,
                        ],
                        [
                            'brand_professional_id' => $brandProfessionalId,
                            'brand_product_id' => $brandProductId,
                            'shopify_product_id' => $normalizedShopifyProductId,
                            'shopify_variant_id' => $normalizedShopifyVariantId,
                            'title' => $this->nullableString((string) ($lineItem['title'] ?? '')),
                            'variant_title' => $this->nullableString((string) ($lineItem['variant_title'] ?? '')),
                            'sku' => $this->nullableString((string) ($lineItem['sku'] ?? '')),
                            'quantity' => $quantity,
                            'gross_line_cents' => $grossLineCents,
                            'discount_line_cents' => $discountLineCents,
                            'refunded_line_cents' => $refundedLineCents,
                            'returned_line_cents' => $returnedLineCents,
                            'net_line_cents' => $netLineCents,
                            'currency_code' => $lineCurrencyCode,
                            'product_snapshot' => [
                                'shopify_line_item_id' => $lineId,
                                'shopify_product_raw' => $shopifyProductRaw,
                                'line_item' => $lineItem,
                            ],
                        ]
                    );

                    [$commissionRate, $rateSource, $rateMetadata] = $this->resolveCommissionRate(
                        $brandProfessionalId,
                        $affiliateProfessionalId,
                        $brandProductId,
                        $commissionCache
                    );

                    $accrualCents = (int) round(($accrualBaseLineCents * $commissionRate) / 100, 0, PHP_ROUND_HALF_UP);
                    $accrualKey = sprintf(
                        'shopify:%s:item:%s:accrual:v1',
                        $shopifyOrderId,
                        $lineId
                    );

                    $accrualEntry = $this->findStripeStorefrontAccrualEntry(
                        $brandProfessionalId,
                        $affiliateProfessionalId,
                        $token,
                        $normalizedShopifyProductId,
                        $lineCurrencyCode,
                        $accrualCents
                    );

                    if ($accrualEntry instanceof CommissionLedgerEntry) {
                        $updates = [];
                        if (empty($accrualEntry->order_id)) {
                            $updates['order_id'] = (string) $order->id;
                        }
                        if (empty($accrualEntry->order_item_id)) {
                            $updates['order_item_id'] = (string) $item->id;
                        }
                        if ($updates !== []) {
                            $accrualEntry->fill($updates);
                            $accrualEntry->save();
                        }
                    }

                    if (! $accrualEntry instanceof CommissionLedgerEntry) {
                        $accrualEntry = CommissionLedgerEntry::query()->firstOrCreate(
                            ['idempotency_key' => $accrualKey],
                            [
                                'order_id' => (string) $order->id,
                                'order_item_id' => (string) $item->id,
                                'brand_professional_id' => $brandProfessionalId,
                                'affiliate_professional_id' => $affiliateProfessionalId,
                                'entry_type' => 'accrual',
                                'status' => 'approved',
                                'amount_cents' => $accrualCents,
                                'currency_code' => $lineCurrencyCode,
                                'commission_rate' => $commissionRate,
                                'rate_source' => $rateSource,
                                'calculation_metadata' => array_merge($rateMetadata, [
                                    'accrual_base_line_cents' => $accrualBaseLineCents,
                                    'current_net_line_cents' => $netLineCents,
                                    'line_id' => $lineId,
                                    'order_id' => (string) $order->id,
                                ]),
                                'occurred_at' => $orderedAt,
                            ]
                        );
                    }

                    $accrualEntryAmount = abs((int) $accrualEntry->amount_cents);
                    if ($accrualEntryAmount <= 0 || $refundedLineCents <= 0 || $accrualBaseLineCents <= 0) {
                        continue;
                    }

                    $targetReversalAbs = (int) round(
                        $accrualEntryAmount * min(1, ($refundedLineCents / max(1, $accrualBaseLineCents))),
                        0,
                        PHP_ROUND_HALF_UP
                    );

                    $existingReversalAbs = (int) CommissionLedgerEntry::query()
                        ->where('order_item_id', (string) $item->id)
                        ->where('entry_type', 'reversal')
                        ->sum(DB::raw('ABS(amount_cents)'));

                    $reversalDelta = max(0, $targetReversalAbs - $existingReversalAbs);
                    if ($reversalDelta <= 0) {
                        continue;
                    }

                    $reversalKey = sprintf(
                        'shopify:%s:item:%s:reversal:%d:v1',
                        $shopifyOrderId,
                        $lineId,
                        $targetReversalAbs
                    );

                    CommissionLedgerEntry::query()->firstOrCreate(
                        ['idempotency_key' => $reversalKey],
                        [
                            'order_id' => (string) $order->id,
                            'order_item_id' => (string) $item->id,
                            'brand_professional_id' => $brandProfessionalId,
                            'affiliate_professional_id' => $affiliateProfessionalId,
                            'entry_type' => 'reversal',
                            'status' => 'approved',
                            'amount_cents' => -1 * $reversalDelta,
                            'currency_code' => $lineCurrencyCode,
                            'commission_rate' => $commissionRate,
                            'rate_source' => 'refund_proportional',
                            'calculation_metadata' => [
                                'line_id' => $lineId,
                                'order_item_id' => (string) $item->id,
                                'accrual_entry_id' => (string) $accrualEntry->id,
                                'accrual_amount_cents' => $accrualEntryAmount,
                                'accrual_base_line_cents' => $accrualBaseLineCents,
                                'refunded_line_cents' => $refundedLineCents,
                                'target_reversal_cents' => $targetReversalAbs,
                                'existing_reversal_cents' => $existingReversalAbs,
                                'delta_reversal_cents' => $reversalDelta,
                            ],
                            'occurred_at' => now(),
                        ]
                    );
                }

                $inbox->status = 'processed';
                $inbox->processed_at = now();
                $inbox->rejection_reason = null;
                $inbox->last_error = null;
                $inbox->save();

                return [
                    'status' => 'processed',
                    'inbox_id' => (string) $inbox->id,
                    'order_id' => (string) $order->id,
                    'shopify_order_id' => $shopifyOrderId,
                    'brand_professional_id' => $brandProfessionalId,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'ordered_day' => $orderedAt->toDateString(),
                    'ordered_hour_start' => $orderedAt->copy()->utc()->startOfHour()->toIso8601String(),
                ];
            });

            if (($result['status'] ?? null) === 'processed') {
                try {
                    app(CommissionPayoutService::class)->processEligiblePayouts();
                } catch (Throwable $payoutException) {
                    Log::warning('Immediate commission payout attempt failed after order processing', [
                        'inbox_id' => $inboxId,
                        'order_id' => $result['order_id'] ?? null,
                        'error' => $payoutException->getMessage(),
                    ]);
                }
            }

            return $result;
        } catch (Throwable $e) {
            OrderEventInbox::query()
                ->where('id', $inboxId)
                ->update([
                    'status' => 'failed',
                    'processed_at' => now(),
                    'last_error' => mb_substr($e->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);

            throw $e;
        }
    }

    /**
     * @return array<string, string>
     */
    private function resolveBrandProductMap(string $brandProfessionalId): array
    {
        $map = [];

        $products = BrandProduct::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->get(['id', 'shopify_product_id']);

        foreach ($products as $product) {
            $productId = (string) $product->id;
            $shopifyProductId = trim((string) $product->shopify_product_id);
            if ($productId === '' || $shopifyProductId === '') {
                continue;
            }

            foreach ($this->shopifyIdKeys($shopifyProductId) as $key) {
                if (! isset($map[$key])) {
                    $map[$key] = $productId;
                }
            }
        }

        return $map;
    }

    private function matchBrandProductId(string $shopifyProductRaw, array $brandProductMap): ?string
    {
        foreach ($this->shopifyIdKeys($shopifyProductRaw) as $key) {
            if (isset($brandProductMap[$key])) {
                return (string) $brandProductMap[$key];
            }
        }

        return null;
    }

    private function normalizeShopifyProductId(string $shopifyProductRaw): ?string
    {
        $shopifyProductRaw = trim($shopifyProductRaw);
        if ($shopifyProductRaw === '') {
            return null;
        }

        if (preg_match('/^(gid:\/\/shopify\/Product\/\d+)$/i', $shopifyProductRaw, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/(\d+)(?!.*\d)/', $shopifyProductRaw, $matches) === 1) {
            return 'gid://shopify/Product/'.$matches[1];
        }

        return $shopifyProductRaw;
    }

    private function normalizeShopifyVariantId(array $lineItem): ?string
    {
        $raw = $this->firstNonEmpty([
            (string) ($lineItem['variant_id'] ?? ''),
            (string) Arr::get($lineItem, 'variant.id', ''),
        ]);

        if ($raw === null) {
            return null;
        }

        if (preg_match('/^(gid:\/\/shopify\/ProductVariant\/\d+)$/i', $raw, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/(\d+)(?!.*\d)/', $raw, $matches) === 1) {
            return 'gid://shopify/ProductVariant/'.$matches[1];
        }

        return $raw;
    }

    /**
     * @return array<int, string>
     */
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

    /**
     * @return array<string, int>
     */
    private function refundsByLineItem(array $payload): array
    {
        $refunds = [];

        $refundRows = collect($payload['refunds'] ?? [])
            ->filter(static fn ($value): bool => is_array($value))
            ->values();

        foreach ($refundRows as $refundRow) {
            $refundLineItems = collect($refundRow['refund_line_items'] ?? [])
                ->filter(static fn ($value): bool => is_array($value))
                ->values();

            foreach ($refundLineItems as $refundLineItem) {
                $lineId = trim((string) ($refundLineItem['line_item_id'] ?? ''));
                if ($lineId === '') {
                    continue;
                }

                $amountCents = $this->toCents(
                    Arr::get(
                        $refundLineItem,
                        'subtotal_set.shop_money.amount',
                        Arr::get($refundLineItem, 'subtotal')
                    )
                );

                if ($amountCents <= 0) {
                    $amountCents = $this->toCents(
                        Arr::get(
                            $refundLineItem,
                            'line_item.price_set.shop_money.amount',
                            Arr::get($refundLineItem, 'line_item.price')
                        )
                    ) * max(1, (int) ($refundLineItem['quantity'] ?? 1));
                }

                $refunds[$lineId] = ($refunds[$lineId] ?? 0) + max(0, $amountCents);
            }
        }

        return $refunds;
    }

    /**
     * Batch-load all commission rates for the line items in an order into the
     * cache structure expected by resolveCommissionRate(). Executes 3 queries
     * regardless of how many line items the order contains.
     *
     * @param  array<int, string|null>  $brandProductIds  Ordered list of resolved brand_product_id per line item (may contain nulls)
     * @return array<string, array{rate: float, source: string, metadata: array<string, mixed>}>
     */
    private function prefetchCommissionRates(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        array $brandProductIds
    ): array {
        $cache = [];
        $nonNullIds = array_values(array_unique(array_filter($brandProductIds, static fn ($id): bool => $id !== null)));

        $affiliateOverrides = [];
        $brandProductOverrides = [];

        if ($nonNullIds !== []) {
            $affiliateOverrides = DB::table('retail.brand_product_affiliate_settings')
                ->where('brand_professional_id', $brandProfessionalId)
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->whereIn('brand_product_id', $nonNullIds)
                ->whereNotNull('commission_override')
                ->pluck('commission_override', 'brand_product_id')
                ->all();

            $brandProductOverrides = DB::table('retail.brand_product_settings')
                ->where('professional_id', $brandProfessionalId)
                ->whereIn('brand_product_id', $nonNullIds)
                ->whereNotNull('commission_override')
                ->pluck('commission_override', 'brand_product_id')
                ->all();
        }

        $brandDefault = DB::table('retail.brand_store_settings')
            ->where('professional_id', $brandProfessionalId)
            ->whereNotNull('default_commission_rate')
            ->value('default_commission_rate');

        $systemDefault = (float) config('comet.store.default_commission_rate', 15);

        foreach ($brandProductIds as $brandProductId) {
            $cacheKey = implode('|', [$brandProfessionalId, $affiliateProfessionalId, $brandProductId ?? 'none']);

            if (isset($cache[$cacheKey])) {
                continue;
            }

            if ($brandProductId !== null && isset($affiliateOverrides[$brandProductId])) {
                $this->storeCommissionRateCache($cache, $cacheKey, (float) $affiliateOverrides[$brandProductId], 'affiliate_product_override', ['brand_product_id' => $brandProductId]);
            } elseif ($brandProductId !== null && isset($brandProductOverrides[$brandProductId])) {
                $this->storeCommissionRateCache($cache, $cacheKey, (float) $brandProductOverrides[$brandProductId], 'brand_product_override', ['brand_product_id' => $brandProductId]);
            } elseif ($brandDefault !== null) {
                $this->storeCommissionRateCache($cache, $cacheKey, (float) $brandDefault, 'brand_default', []);
            } else {
                $this->storeCommissionRateCache($cache, $cacheKey, $systemDefault, 'system_default', []);
            }
        }

        return $cache;
    }

    /**
     * @param  array<string, array{rate: float, source: string, metadata: array<string, mixed>}>  $cache
     * @return array{0: float, 1: string, 2: array<string, mixed>}
     */
    private function resolveCommissionRate(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        ?string $brandProductId,
        array &$cache
    ): array {
        $cacheKey = implode('|', [$brandProfessionalId, $affiliateProfessionalId, $brandProductId ?? 'none']);
        if (isset($cache[$cacheKey])) {
            $cached = $cache[$cacheKey];

            return [$cached['rate'], $cached['source'], $cached['metadata']];
        }

        if ($brandProductId !== null) {
            $affiliateOverride = DB::table('retail.brand_product_affiliate_settings as bpas')
                ->where('bpas.brand_professional_id', $brandProfessionalId)
                ->where('bpas.affiliate_professional_id', $affiliateProfessionalId)
                ->where('bpas.brand_product_id', $brandProductId)
                ->whereNotNull('bpas.commission_override')
                ->value('bpas.commission_override');

            if ($affiliateOverride !== null) {
                return $this->storeCommissionRateCache(
                    $cache,
                    $cacheKey,
                    (float) $affiliateOverride,
                    'affiliate_product_override',
                    ['brand_product_id' => $brandProductId]
                );
            }

            $brandOverride = DB::table('retail.brand_product_settings as bps')
                ->where('bps.professional_id', $brandProfessionalId)
                ->where('bps.brand_product_id', $brandProductId)
                ->whereNotNull('bps.commission_override')
                ->value('bps.commission_override');

            if ($brandOverride !== null) {
                return $this->storeCommissionRateCache(
                    $cache,
                    $cacheKey,
                    (float) $brandOverride,
                    'brand_product_override',
                    ['brand_product_id' => $brandProductId]
                );
            }
        }

        $brandDefault = DB::table('retail.brand_store_settings as bss')
            ->where('bss.professional_id', $brandProfessionalId)
            ->whereNotNull('bss.default_commission_rate')
            ->value('bss.default_commission_rate');

        if ($brandDefault !== null) {
            return $this->storeCommissionRateCache(
                $cache,
                $cacheKey,
                (float) $brandDefault,
                'brand_default',
                []
            );
        }

        return $this->storeCommissionRateCache(
            $cache,
            $cacheKey,
            (float) config('comet.store.default_commission_rate', 15),
            'system_default',
            []
        );
    }

    /**
     * @param  array<string, array{rate: float, source: string, metadata: array<string, mixed>}>  $cache
     * @param  array<string, mixed>  $metadata
     * @return array{0: float, 1: string, 2: array<string, mixed>}
     */
    private function storeCommissionRateCache(
        array &$cache,
        string $cacheKey,
        float $rate,
        string $source,
        array $metadata
    ): array {
        $normalizedRate = round(max(0.0, min(100.0, $rate)), 4);

        $cache[$cacheKey] = [
            'rate' => $normalizedRate,
            'source' => $source,
            'metadata' => $metadata,
        ];

        return [$normalizedRate, $source, $metadata];
    }

    /**
     * @return array<string, mixed>
     */
    private function rejectLockedInbox(OrderEventInbox $inbox, string $reason): array
    {
        $inbox->status = 'rejected';
        $inbox->rejection_reason = $reason;
        $inbox->last_error = null;
        $inbox->processed_at = now();
        $inbox->save();

        return [
            'status' => 'rejected',
            'inbox_id' => (string) $inbox->id,
            'reason' => $reason,
        ];
    }

    private function resolveLifecycleStatus(array $payload): string
    {
        if (! empty($payload['cancelled_at'])) {
            return 'cancelled';
        }

        if (! empty($payload['closed_at'])) {
            return 'closed';
        }

        return 'open';
    }

    private function resolveOrderSource(array $payload): string
    {
        $note = strtolower(trim((string) ($payload['note'] ?? '')));
        if ($note !== '' && str_contains($note, 'comet_payment_mode:stripe_direct')) {
            return 'stripe_direct';
        }

        foreach (collect($payload['note_attributes'] ?? [])->filter(static fn ($value): bool => is_array($value)) as $row) {
            $name = strtolower(trim((string) ($row['name'] ?? '')));
            $value = strtolower(trim((string) ($row['value'] ?? '')));

            if (
                in_array($name, ['comet_payment_mode', 'cometpaymentmode'], true)
                && in_array($value, ['stripe_direct', 'stripe'], true)
            ) {
                return 'stripe_direct';
            }
        }

        $tags = strtolower(trim((string) ($payload['tags'] ?? '')));
        if ($tags !== '' && str_contains($tags, 'comet_payment_mode:stripe_direct')) {
            return 'stripe_direct';
        }

        return 'shopify';
    }

    private function resolveFinancialStatus(string $financialStatus): string
    {
        $status = strtolower(trim($financialStatus));

        return in_array($status, ['pending', 'authorized', 'paid', 'partially_refunded', 'refunded', 'voided'], true)
            ? $status
            : 'pending';
    }

    private function resolveFulfillmentStatus(string $fulfillmentStatus): string
    {
        $status = strtolower(trim($fulfillmentStatus));

        return in_array($status, ['unfulfilled', 'partial', 'fulfilled', 'restocked'], true)
            ? $status
            : 'unfulfilled';
    }

    private function extractCometSessionToken(array $payload): ?string
    {
        $keys = ['comet_session', 'cometSession', 'comet_session_token', 'cometSessionToken'];
        $candidates = [];

        foreach (collect($payload['note_attributes'] ?? [])->filter(static fn ($value): bool => is_array($value)) as $row) {
            $name = strtolower(trim((string) ($row['name'] ?? '')));
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            if (in_array($name, array_map('strtolower', $keys), true)) {
                $candidates[] = $value;
            }
        }

        foreach ($keys as $key) {
            $candidate = trim((string) (
                Arr::get($payload, 'attributes.'.$key)
                ?? Arr::get($payload, 'client_details.'.$key)
                ?? Arr::get($payload, 'note_attributes_map.'.$key)
                ?? ''
            ));

            if ($candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        $landingSite = trim((string) ($payload['landing_site'] ?? ''));
        if ($landingSite !== '') {
            $parts = parse_url($landingSite);
            parse_str((string) ($parts['query'] ?? ''), $queryParams);

            $queryToken = trim((string) ($queryParams['comet_session'] ?? ''));
            if ($queryToken !== '') {
                $candidates[] = $queryToken;
            }
        }

        $note = trim((string) ($payload['note'] ?? ''));
        if ($note !== '' && preg_match('/comet[_-]?session\s*[:=]\s*([A-Za-z0-9._-]+)/i', $note, $matches) === 1) {
            $candidates[] = $matches[1];
        }

        foreach ($candidates as $candidate) {
            $token = trim((string) $candidate);
            if ($token === '') {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9._-]+$/', $token) === 1) {
                return $token;
            }
        }

        return null;
    }

    private function findStripeStorefrontAccrualEntry(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        string $checkoutSessionToken,
        ?string $normalizedShopifyProductId,
        string $currencyCode,
        int $amountCents,
    ): ?CommissionLedgerEntry {
        $checkoutSessionToken = trim($checkoutSessionToken);
        if ($checkoutSessionToken === '' || $amountCents <= 0) {
            return null;
        }

        $query = CommissionLedgerEntry::query()
            ->where('entry_type', 'accrual')
            ->where('status', 'approved')
            ->where('rate_source', 'stripe_storefront')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->where('currency_code', $currencyCode)
            ->where('amount_cents', $amountCents)
            ->whereRaw("coalesce(calculation_metadata->>'checkout_session_token', '') = ?", [$checkoutSessionToken]);

        if ($normalizedShopifyProductId !== null && $normalizedShopifyProductId !== '') {
            $query->whereRaw(
                "coalesce(lower(calculation_metadata->>'shopify_product_id'), '') = ?",
                [strtolower($normalizedShopifyProductId)]
            );
        }

        return $query
            ->orderByDesc('created_at')
            ->first();
    }

    private function toCents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_numeric((string) $value)) {
            return 0;
        }

        return max(0, (int) round(((float) $value) * 100));
    }

    private function nullableTimestamp(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            // Normalize incoming Shopify timestamps to UTC so persisted
            // timestamptz values don't drift across server/app timezones.
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
