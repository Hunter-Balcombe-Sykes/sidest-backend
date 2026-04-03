<?php

namespace App\Services\Cache;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

// V2: Visit/click stats caching with version-token invalidation for bulk cache busting.
class AnalyticsCacheService
{
    public function getVisitStats(string $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = CacheKeyGenerator:: analyticsVisits(
            $professionalId,
            $startDate->format('Ymd'),
            $endDate->format('Ymd')
        );

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $startDate, $endDate) {
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
                ->toArray();
        });
    }

    public function getClickStats(string $professionalId, Carbon $startDate, Carbon $endDate): array
    {
        $cacheKey = CacheKeyGenerator::analyticsClicks(
            $professionalId,
            $startDate->format('Ymd'),
            $endDate->format('Ymd')
        );

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $startDate, $endDate) {
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

    public function invalidateAnalytics(string $professionalId): void
    {
        // Bump the version token so every cached summary for this professional
        // becomes unreachable immediately, regardless of date-range or granularity.
        // The stale entries will expire on their own TTL (≤ 24 h).
        Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($professionalId));

        // Delete the rolling 90-day window of visit and click stat keys.
        $keys = [];
        $end = Carbon::now();

        for ($i = 0; $i < 90; $i++) {
            $date = $end->copy()->subDays($i);
            $start = $date->format('Ymd');
            $endStr = $end->format('Ymd');

            $keys[] = CacheKeyGenerator::analyticsVisits($professionalId, $start, $endStr);
            $keys[] = CacheKeyGenerator::analyticsClicks($professionalId, $start, $endStr);
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
    }
}
