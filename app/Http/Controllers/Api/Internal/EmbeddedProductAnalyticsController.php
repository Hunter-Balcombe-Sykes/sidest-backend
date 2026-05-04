<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionLedgerEntry;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Backs the affiliate-product-block Shopify admin UI extension.
// Returns 30-day affiliate sales for a single product: totals, variant rollup,
// recent sales, and the Side St active state.
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

        // Pull the matching ledger rows once and roll everything up in PHP —
        // the JSON path filters need to run anyway, and at most a brand has a
        // few thousand commission entries per product per month.
        $entries = CommissionLedgerEntry::with('affiliateProfessional:id,display_name')
            ->where('brand_professional_id', $professionalId)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->where('occurred_at', '>=', $thirtyDaysAgo)
            ->whereRaw("calculation_metadata->>'product_id' = ?", [$productId])
            ->orderByDesc('occurred_at')
            ->get();

        $totalUnits = 0;
        $totalRevenueCents = 0;
        $totalCommissionCents = 0;
        $weightedRateSum = 0.0;
        $rateWeight = 0;
        $currency = 'AUD';

        // variant_id => { variant_id, variant_title, units, revenue_cents }
        $variants = [];

        foreach ($entries as $entry) {
            $meta = is_array($entry->calculation_metadata) ? $entry->calculation_metadata : [];
            $qty = (int) ($meta['quantity'] ?? 0);
            $linePost = (float) ($meta['line_price_post_discount'] ?? 0);
            $revenueCents = (int) round($linePost * 100);

            $totalUnits += $qty;
            $totalRevenueCents += $revenueCents;
            $totalCommissionCents += (int) $entry->amount_cents;
            $weightedRateSum += ((float) $entry->commission_rate) * (int) $entry->amount_cents;
            $rateWeight += (int) $entry->amount_cents;
            $currency = (string) ($entry->currency_code ?? $currency);

            $variantId = (string) ($meta['variant_id'] ?? '');
            $variantKey = $variantId !== '' ? $variantId : '__no_variant__';

            if (! isset($variants[$variantKey])) {
                $variants[$variantKey] = [
                    'variant_id' => $variantId,
                    'variant_title' => (string) ($meta['variant_title'] ?? ''),
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

        $recentSales = $entries->take(5)->map(function (CommissionLedgerEntry $entry) {
            $meta = is_array($entry->calculation_metadata) ? $entry->calculation_metadata : [];
            $linePost = (float) ($meta['line_price_post_discount'] ?? 0);

            return [
                'affiliate_name' => (string) ($entry->affiliateProfessional?->display_name ?? 'Unknown'),
                'quantity' => (int) ($meta['quantity'] ?? 0),
                'revenue_cents' => (int) round($linePost * 100),
                'commission_cents' => (int) $entry->amount_cents,
                'occurred_at' => $entry->occurred_at?->toIso8601String(),
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
