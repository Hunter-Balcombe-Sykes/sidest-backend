<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\Professional;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Backs the affiliate-product-block Shopify admin UI extension.
// Returns 30-day affiliate sales for a single product: totals, variant rollup,
// recent sales, and the Partna active state.
//
// Cached per (brand, product) for 5 minutes — the variant rollup query is
// non-trivial and the data only refreshes on Shopify orders/paid webhooks.
class EmbeddedProductAnalyticsController extends ApiController
{
    public function __construct(
        private readonly BrandCatalogService $catalog,
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
        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $productId = (string) preg_replace('#^gid://shopify/Product/#', '', $shopifyProductId);

        $cacheKey = "embedded:product-analytics:{$professionalId}:{$productId}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $productId) {
            return $this->success($this->build($professionalId, $productId));
        });
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
     * Look up the sidest.active metafield for this product. Null when the
     * metafield isn't set yet (newly synced product).
     */
    private function resolveActive(string $professionalId, string $productId): ?bool
    {
        try {
            $professional = Professional::findOrFail($professionalId);
            $catalog = $this->catalog->fetchBrandCatalog($professional);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($catalog)) {
            return null;
        }

        $needle = "gid://shopify/Product/{$productId}";

        foreach ($catalog as $product) {
            if (($product['gid'] ?? null) === $needle) {
                $value = $product['metafields']['active'] ?? null;

                return is_bool($value) ? $value : null;
            }
        }

        return null;
    }
}
