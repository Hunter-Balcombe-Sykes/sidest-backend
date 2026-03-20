<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolvesSubdomainFromHost;
use App\Services\Public\PublicSiteResolver;
use App\Services\Store\BrandProductCatalogService;
use App\Services\Store\FeaturedProductsPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicStoreController extends ApiController
{
    use ResolvesSubdomainFromHost;

    public function __construct(
        private readonly PublicSiteResolver $siteResolver,
        private readonly FeaturedProductsPayloadService $featuredProductsPayloads,
        private readonly BrandProductCatalogService $catalog
    ) {}

    /**
     * GET /public/store/featured-products
     * GET /public/store/featured-products-by-slug (header-based fallback)
     * Returns default product selections payload for the resolved site.
     */
    public function featuredProducts(Request $request): JsonResponse
    {
        $subdomain = $this->resolveSiteSubdomain($request);
        if (! $subdomain) {
            return $this->error('Missing site identifier.', 400);
        }

        $site = $this->siteResolver->resolvePublishedSite($subdomain);
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        return $this->success(
            $this->featuredProductsPayloads->build(
                (string) $site->professional_id,
                'public_store'
            )
        );
    }

    /**
     * POST /public/store/order-analytics
     * POST /public/store/order-analytics-by-slug (header-based fallback)
     * Capture store checkout analytics directly from the site checkout flow.
     */
    public function recordOrderAnalytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:50'],
            'order_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'draft_order_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'order_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'order_value_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'line_items' => ['sometimes', 'array'],
            'line_items.*.shopify_product_id' => ['required_with:line_items', 'string', 'max:255'],
            'line_items.*.shopify_variant_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_items.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_items.*.variant_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_items.*.quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'line_items.*.unit_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'line_items.*.line_total_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'line_items.*.currency_code' => ['sometimes', 'nullable', 'string', 'max:10'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $subdomain = $this->resolveSiteSubdomain($request);
        if (! $subdomain) {
            return $this->error('Missing site identifier.', 400);
        }

        $site = $this->siteResolver->resolvePublishedSite($subdomain);
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $professionalId = (string) $site->professional_id;
        $catalogRows = $this->catalog->selectedProductsForProfessional($professionalId);
        $catalogByShopifyId = [];
        foreach ($catalogRows as $row) {
            $shopifyProductId = trim((string) ($row['shopify_product_id'] ?? ''));
            if ($shopifyProductId === '') {
                continue;
            }

            foreach ($this->shopifyIdKeys($shopifyProductId) as $key) {
                if (! array_key_exists($key, $catalogByShopifyId)) {
                    $catalogByShopifyId[$key] = $row;
                }
            }
        }

        $lineItems = collect($validated['line_items'] ?? [])
            ->map(function ($item) use ($catalogByShopifyId): array {
                $shopifyProductId = trim((string) ($item['shopify_product_id'] ?? ''));
                $shopifyVariantId = $this->nullableString($item['shopify_variant_id'] ?? null);
                $title = $this->nullableString($item['title'] ?? null);
                $variantTitle = $this->nullableString($item['variant_title'] ?? null);
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $unitPriceCents = $this->toNullableInt($item['unit_price_cents'] ?? null);
                $lineTotalCents = $this->toNullableInt($item['line_total_cents'] ?? null);
                if ($lineTotalCents === null && $unitPriceCents !== null) {
                    $lineTotalCents = $unitPriceCents * $quantity;
                }
                if ($unitPriceCents === null && $lineTotalCents !== null && $quantity > 0) {
                    $unitPriceCents = (int) floor($lineTotalCents / $quantity);
                }

                $currencyCode = strtoupper(trim((string) ($item['currency_code'] ?? 'AUD')));
                if ($currencyCode === '') {
                    $currencyCode = 'AUD';
                }

                $catalogRow = null;
                foreach ($this->shopifyIdKeys($shopifyProductId) as $key) {
                    if (array_key_exists($key, $catalogByShopifyId)) {
                        $catalogRow = $catalogByShopifyId[$key];
                        break;
                    }
                }

                return [
                    'shopify_product_id' => $shopifyProductId,
                    'shopify_variant_id' => $shopifyVariantId,
                    'title' => $title,
                    'variant_title' => $variantTitle,
                    'quantity' => $quantity,
                    'unit_price_cents' => $unitPriceCents,
                    'line_total_cents' => $lineTotalCents,
                    'currency_code' => $currencyCode,
                    'brand_product_id' => isset($catalogRow['brand_product_id'])
                        ? (string) $catalogRow['brand_product_id']
                        : null,
                    'brand_professional_id' => isset($catalogRow['brand_professional_id'])
                        ? (string) $catalogRow['brand_professional_id']
                        : null,
                ];
            })
            ->filter(fn (array $item): bool => $item['shopify_product_id'] !== '')
            ->values();

        $lineItemsList = $lineItems->all();
        $orderValueCents = $this->toNullableInt($validated['order_value_cents'] ?? null);
        if ($orderValueCents === null) {
            $orderValueCents = (int) $lineItems->sum(fn (array $item): int => (int) ($item['line_total_cents'] ?? 0));
        }
        $currencyCode = strtoupper(trim((string) ($validated['currency_code'] ?? '')));
        if ($currencyCode === '' && $lineItems->isNotEmpty()) {
            $currencyCode = (string) ($lineItems->first()['currency_code'] ?? 'AUD');
        }
        if ($currencyCode === '') {
            $currencyCode = 'AUD';
        }

        $eventId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use (
            $eventId,
            $professionalId,
            $site,
            $subdomain,
            $validated,
            $orderValueCents,
            $currencyCode,
            $lineItemsList,
            $now
        ): void {
            DB::table('analytics.store_order_events')->insert([
                'id' => $eventId,
                'professional_id' => $professionalId,
                'site_id' => (string) $site->id,
                'occurred_at' => $now,
                'source' => 'site_checkout',
                'subdomain' => $subdomain,
                'payment_method' => $this->nullableString($validated['payment_method'] ?? null),
                'customer_name' => $this->nullableString($validated['customer_name'] ?? null),
                'customer_email' => $this->nullableString($validated['customer_email'] ?? null),
                'customer_phone' => $this->nullableString($validated['customer_phone'] ?? null),
                'order_name' => $this->nullableString($validated['order_name'] ?? null),
                'draft_order_id' => $this->nullableString($validated['draft_order_id'] ?? null),
                'order_id' => $this->nullableString($validated['order_id'] ?? null),
                'currency_code' => $currencyCode,
                'order_value_cents' => max(0, (int) $orderValueCents),
                'line_item_count' => (int) collect($lineItemsList)->sum('quantity'),
                'raw_payload' => json_encode($validated),
                'created_at' => $now,
            ]);

            if ($lineItemsList === []) {
                return;
            }

            $itemRows = collect($lineItemsList)
                ->map(function (array $item) use ($eventId, $professionalId, $site, $now): array {
                    return [
                        'id' => (string) Str::uuid(),
                        'event_id' => $eventId,
                        'professional_id' => $professionalId,
                        'site_id' => (string) $site->id,
                        'brand_professional_id' => $item['brand_professional_id'],
                        'brand_product_id' => $item['brand_product_id'],
                        'shopify_product_id' => $item['shopify_product_id'],
                        'shopify_variant_id' => $item['shopify_variant_id'],
                        'title' => $item['title'],
                        'variant_title' => $item['variant_title'],
                        'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                        'unit_price_cents' => $item['unit_price_cents'],
                        'line_total_cents' => $item['line_total_cents'],
                        'currency_code' => $item['currency_code'] ?: 'AUD',
                        'metadata' => json_encode([
                            'matched_brand_product' => $item['brand_product_id'] !== null,
                        ]),
                        'created_at' => $now,
                    ];
                })
                ->all();

            DB::table('analytics.store_order_event_items')->insert($itemRows);
        });

        return $this->success([
            'message' => 'Store order analytics recorded.',
            'event_id' => $eventId,
            'line_items_recorded' => count($lineItemsList),
        ], 201);
    }

    private function resolveSiteSubdomain(Request $request): ?string
    {
        $fromHeader = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($fromHeader !== '') {
            return strtolower($fromHeader);
        }

        $fromQuery = trim((string) $request->query('slug', ''));
        if ($fromQuery !== '') {
            return strtolower($fromQuery);
        }

        $fromInput = trim((string) $request->input('slug', ''));
        if ($fromInput !== '') {
            return strtolower($fromInput);
        }

        $fromHost = $this->resolveSubdomainFromHost($request);
        if (is_string($fromHost) && $fromHost !== '') {
            return strtolower($fromHost);
        }

        return null;
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
            $keys[] = 'gid://shopify/product/'.$digits;
        }

        return array_values(array_unique(array_filter($keys, fn (string $key): bool => $key !== '')));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric((string) $value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
