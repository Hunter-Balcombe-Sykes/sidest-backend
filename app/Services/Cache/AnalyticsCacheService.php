<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;

// V2: Commerce-write fan-out for analytics caches. Bumps the per-professional version
// token (atomically busting every windowed summary key) and issues targeted forgets
// for affiliate projection variants, the embedded setup overview, and per-product
// blocks. Replaces the legacy 90-day enumerate-and-delete loop.
class AnalyticsCacheService
{
    /**
     * Atomically invalidates every windowed cache variant for this professional
     * by incrementing the version token embedded in all analytics cache keys.
     * Reference: docs/caching-gold-standard.md §7.5.
     */
    public function bumpAnalyticsVersion(string $professionalId): void
    {
        Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($professionalId));
    }

    public function invalidateAnalytics(string $professionalId): void
    {
        // Bump the version token so every cached summary for this professional
        // becomes unreachable immediately, regardless of date-range or granularity.
        // The stale entries will expire on their own TTL (≤ 24 h).
        $this->bumpAnalyticsVersion($professionalId);

        // Drop every variant of the affiliate projections cache (adaptive default + each
        // window-tier override). Driven from config so that adding/removing a tier in
        // `partna.commerce_analytics.projections_window_tiers` automatically updates
        // invalidation — pragmatic explicit list rather than wildcard SCAN.
        $projectionVariants = array_merge(
            [null],
            (array) config('partna.commerce_analytics.projections_window_tiers', [90, 60, 30, 14])
        );
        foreach ($projectionVariants as $w) {
            $w = $w === null ? null : (int) $w;
            Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId, $w));
            Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId, $w).':stale');
        }

        // Bust the embedded setup overview panel (affiliate count + commission
        // totals + recent sales) so brands see live numbers after any commerce write.
        Cache::forget(CacheKeyGenerator::embeddedSetupOverview($professionalId));
        Cache::forget(CacheKeyGenerator::embeddedSetupOverview($professionalId).':stale');
    }

    /**
     * Bust per-product analytics caches for a brand's set of Shopify product
     * IDs. Called from the order webhook jobs (paid / updated / refund) with
     * the numeric `product_id` from each affected line item so the embedded
     * product-block sees the new sale within seconds instead of waiting for
     * the 5-minute TTL.
     *
     * Product IDs are Shopify's numeric form (no `gid://` prefix) — matches
     * the cache-key shape EmbeddedProductAnalyticsController writes.
     *
     * @param  array<int, string|int>  $productIds  Numeric Shopify product IDs (empty values skipped)
     */
    public function invalidateProductAnalytics(string $professionalId, array $productIds): void
    {
        $keys = [];
        foreach (array_unique($productIds) as $productId) {
            $productId = (string) $productId;
            if ($productId === '') {
                continue;
            }
            $key = CacheKeyGenerator::embeddedProductAnalytics($professionalId, $productId);
            $keys[] = $key;
            $keys[] = $key.':stale';
        }

        if ($keys === []) {
            return;
        }

        Cache::deleteMultiple($keys);
    }
}
