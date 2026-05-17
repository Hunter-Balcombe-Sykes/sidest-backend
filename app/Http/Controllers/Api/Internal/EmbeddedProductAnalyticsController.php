<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveEmbeddedProfessional;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Backs the affiliate-product-block Shopify admin UI extension.
// Returns 30-day affiliate sales for a single product: totals, variant rollup,
// recent sales, and the Partna active state.
//
// Cached per (brand, product) for 5 minutes — the variant rollup query is
// non-trivial and the data only refreshes on Shopify orders/paid webhooks.
class EmbeddedProductAnalyticsController extends ApiController
{
    use ResolveEmbeddedProfessional;

    public function __construct(
        private readonly BrandCatalogService $catalog,
        private readonly CacheLockService $cacheLock,
    ) {}

    /**
     * @return JsonResponse {
     *                      product_id: string,
     *                      active: bool|null,
     *                      currency_code: string,
     *                      period_days: int,
     *                      totals: { units: int, revenue_cents: int, commission_cents: int, avg_commission_rate: float },
     *                      variants: Array<{ variant_id, variant_title, units, revenue_cents }>,
     *                      recent_sales: Array<{ affiliate_name, quantity, revenue_cents, commission_cents, occurred_at }>,
     *                      }
     */
    public function show(Request $request, string $shopifyProductId): JsonResponse
    {
        $professionalId = $this->currentEmbeddedProfessionalId($request);
        $productId = (string) preg_replace('#^gid://shopify/Product/#', '', $shopifyProductId);

        $cacheKey = CacheKeyGenerator::embeddedProductAnalytics($professionalId, $productId);

        // Int TTL (300s) — DateTimeInterface TTLs skip writeWithJitter's ±20%
        // jitter. Bust on every order/refund webhook via
        // AnalyticsCacheService::invalidateProductAnalytics() so the rollup
        // reflects new sales within seconds, not 5 minutes.
        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            300,
            fn () => $this->build($professionalId, $productId),
        );

        return $this->success($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(string $professionalId, string $productId): array
    {
        $thirtyDaysAgo = now()->subDays(30);

        // Phase 4+: read from commerce.order_items (denormalized per-line) joined to commerce.orders
        // for status filtering. Excludes stub/cancelled/voided/refunded so they don't pollute totals.
        // The webhook job pre-computes per-line commission_cents/commission_rate into the line_items
        // JSONB; the order_items_diff trigger mirrors them into this table.
        $rows = DB::table('commerce.order_items as oi')
            ->join('commerce.orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('core.professionals as p', 'p.id', '=', 'oi.affiliate_professional_id')
            ->where('oi.brand_professional_id', $professionalId)
            ->where('oi.shopify_product_id', $productId)
            ->where('oi.occurred_at', '>=', $thirtyDaysAgo)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->orderByDesc('oi.occurred_at')
            ->get([
                'oi.shopify_variant_id',
                'oi.title',
                'oi.quantity',
                'oi.line_total_cents',
                'oi.commission_cents',
                'oi.commission_rate',
                'oi.currency_code',
                'oi.occurred_at',
                'oi.affiliate_professional_id',
                'p.display_name as affiliate_name',
            ]);

        $totalUnits = 0;
        $totalRevenueCents = 0;
        $totalCommissionCents = 0;
        $weightedRateSum = 0.0;
        $rateWeight = 0;
        $currency = 'AUD';

        // variant_id => { variant_id, variant_title, units, revenue_cents }
        $variants = [];

        foreach ($rows as $row) {
            $qty = (int) $row->quantity;
            $revenueCents = (int) $row->line_total_cents;
            $commissionCents = (int) $row->commission_cents;

            $totalUnits += $qty;
            $totalRevenueCents += $revenueCents;
            $totalCommissionCents += $commissionCents;
            $weightedRateSum += ((float) $row->commission_rate) * $commissionCents;
            $rateWeight += $commissionCents;
            $currency = (string) ($row->currency_code ?? $currency);

            $variantId = (string) ($row->shopify_variant_id ?? '');
            $variantKey = $variantId !== '' ? $variantId : '__no_variant__';

            if (! isset($variants[$variantKey])) {
                // Shopify line_item.variant_title is not captured into order_items
                // (only line_item.title is mirrored). For variant-aware display the
                // line title is the closest stable proxy; pure-product orders show
                // the product title, variant orders show whatever Shopify formatted.
                $variants[$variantKey] = [
                    'variant_id' => $variantId,
                    'variant_title' => (string) ($row->title ?? ''),
                    'units' => 0,
                    'revenue_cents' => 0,
                ];
            }
            $variants[$variantKey]['units'] += $qty;
            $variants[$variantKey]['revenue_cents'] += $revenueCents;
        }

        // Sort variants by revenue desc, drop the synthetic key.
        $variantsList = array_values($variants);
        usort($variantsList, fn ($a, $b) => $b['revenue_cents'] <=> $a['revenue_cents']);

        $recentSales = $rows->take(5)->map(function ($row) {
            $occurredAt = $row->occurred_at !== null ? \Illuminate\Support\Carbon::parse($row->occurred_at) : null;

            return [
                'affiliate_name' => (string) ($row->affiliate_name ?? 'Unknown'),
                'quantity' => (int) $row->quantity,
                'revenue_cents' => (int) $row->line_total_cents,
                'commission_cents' => (int) $row->commission_cents,
                'occurred_at' => $occurredAt?->toIso8601String(),
            ];
        })->values()->all();

        $avgRate = $rateWeight > 0 ? $weightedRateSum / $rateWeight : 0.0;

        return [
            'product_id' => $productId,
            'active' => $this->resolveActive($professionalId, $productId),
            'currency_code' => $currency,
            'period_days' => 30,
            'totals' => [
                'units' => $totalUnits,
                'revenue_cents' => $totalRevenueCents,
                'commission_cents' => $totalCommissionCents,
                'avg_commission_rate' => round($avgRate, 2),
            ],
            'variants' => $variantsList,
            'recent_sales' => $recentSales,
        ];
    }

    /**
     * Look up the sidest.active metafield for this product, single-product
     * scope (no full-catalog fetch). Cached per (brand, product) for 10
     * minutes so concurrent analytics requests on the same product never
     * race against the Shopify Admin budget. Null when the metafield isn't
     * set yet (newly synced product) or when Shopify is unreachable.
     *
     * TTL layering: this inner cache (10m) is wrapped by show()'s outer
     * analytics cache (5m). On an outer hit, this method isn't called; the
     * inner cache only fires on outer cold-miss + concurrent fetches for the
     * same product, where it dedupes the Shopify hit to one call. Bust path:
     * BrandCatalogService::bustCatalogCaches drops this key when `active` is
     * written via the dashboard. EmbeddedProductSettingsController writes do
     * NOT currently bust it (deferred Step 3 — Master Pattern 17 follow-up).
     *
     * Master Pattern 17 / DB-F#SCALE-6.
     */
    private function resolveActive(string $professionalId, string $productId): ?bool
    {
        $cacheKey = CacheKeyGenerator::embeddedProductActive($professionalId, $productId);

        return $this->cacheLock->rememberLockedNullable(
            $cacheKey,
            600, // 10 minutes
            function () use ($professionalId, $productId) {
                $integration = ProfessionalIntegration::query()
                    ->where('professional_id', $professionalId)
                    ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                    ->first();

                if (! $integration) {
                    return null;
                }

                try {
                    return $this->catalog->fetchProductActiveMetafield(
                        $integration,
                        "gid://shopify/Product/{$productId}",
                    );
                } catch (\Throwable) {
                    return null;
                }
            },
        );
    }
}
