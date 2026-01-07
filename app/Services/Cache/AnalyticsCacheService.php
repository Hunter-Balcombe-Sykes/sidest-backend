<?php

namespace App\Services\Cache;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

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
            return LinkClick::where('professional_id', $professionalId)
                ->whereBetween('occurred_at', [$startDate, $endDate])
                ->selectRaw('
                    COUNT(*) as total_clicks,
                    COUNT(DISTINCT visitor_id) as unique_clickers,
                    COUNT(DISTINCT link_block_id) as links_clicked
                ')
                ->first()
                ->toArray();
        });
    }

    public function invalidateAnalytics(string $professionalId): void
    {
        // Invalidate last 90 days of analytics cache
        $end = Carbon::now();
        for ($i = 0; $i < 90; $i++) {
            $date = $end->copy()->subDays($i);
            $keys[] = CacheKeyGenerator::analyticsVisits($professionalId, $date->format('Ymd'), $end->format('Ymd'));
            $keys[] = CacheKeyGenerator::analyticsClicks($professionalId, $date->format('Ymd'), $end->format('Ymd'));
        }

        Cache::deleteMultiple($keys ??  []);
    }
}
