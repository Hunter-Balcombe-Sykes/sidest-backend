<?php

namespace App\Services\Cache;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

// V2: Visit/click stats caching with version-token invalidation for bulk cache busting.
class AnalyticsCacheService
{
    public function __construct(private CacheLockService $cacheLock) {}

    public function getVisitStats(string $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = CacheKeyGenerator::analyticsVisits(
            $professionalId,
            $startDate->format('Ymd'),
            $endDate->format('Ymd')
        );

        return $this->cacheLock->rememberLocked($cacheKey, (int) config('partna.cache.ttls.analytics_short'), function () use ($professionalId, $startDate, $endDate) {
            return SiteVisit::where('professional_id', $professionalId)
                ->whereBetween('occurred_at', [$startDate, $endDate])
                ->selectRaw('
                    COUNT(*) as total_visits,
                    COUNT(DISTINCT visitor_id) as unique_visitors,
                    COUNT(DISTINCT DATE(occurred_at)) as days_with_visits,
                    COUNT(DISTINCT country_code) as unique_countries,
                    COUNT(DISTINCT device_type) as device_types
                ')
                ->first()
                ?->toArray() ?? [
                    'total_visits' => 0,
                    'unique_visitors' => 0,
                    'days_with_visits' => 0,
                    'unique_countries' => 0,
                    'device_types' => 0,
                ];
        });
    }

    public function getClickStats(string $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = CacheKeyGenerator::analyticsClicks(
            $professionalId,
            $startDate->format('Ymd'),
            $endDate->format('Ymd')
        );

        return $this->cacheLock->rememberLocked($cacheKey, (int) config('partna.cache.ttls.analytics_short'), function () use ($professionalId, $startDate, $endDate) {
            return LinkClick::runForBlockForeignKey(
                function (string $blockColumn) use ($professionalId, $startDate, $endDate) {
                    return LinkClick::where('professional_id', $professionalId)
                        ->whereBetween('occurred_at', [$startDate, $endDate])
                        ->selectRaw("
                            COUNT(*) as total_clicks,
                            COUNT(DISTINCT visitor_id) as unique_clickers,
                            COUNT(DISTINCT {$blockColumn}) as links_clicked
                        ")
                        ->first()
                        ?->toArray() ?? [
                            'total_clicks' => 0,
                            'unique_clickers' => 0,
                            'links_clicked' => 0,
                        ];
                },
                [
                    'total_clicks' => 0,
                    'unique_clickers' => 0,
                    'links_clicked' => 0,
                ]
            );
        });
    }

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

        // Delete the rolling 90-day window of visit and click stat keys.
        // Each entry covers a single day (start === end === that day's date).
        $keys = [];

        for ($i = 0; $i < 90; $i++) {
            $date = Carbon::now()->subDays($i)->format('Ymd');

            $keys[] = CacheKeyGenerator::analyticsVisits($professionalId, $date, $date);
            $keys[] = CacheKeyGenerator::analyticsClicks($professionalId, $date, $date);
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
    }
}
