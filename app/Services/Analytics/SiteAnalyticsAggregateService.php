<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Concerns\ResolvesTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// V2: Aggregates site visits and clicks into hourly/daily metrics with transactional safety and advisory locks.
class SiteAnalyticsAggregateService
{
    use ResolvesTimezone;

    public function rebuildProfessionalHour(string $professionalId, Carbon|string $hourStart): void
    {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $hour = Carbon::parse($hourStart)->utc()->startOfHour();
        $hourEnd = $hour->copy()->addHour();
        $timezone = $this->professionalTimezone($professionalId);
        $now = now();

        DB::transaction(function () use ($professionalId, $hour, $hourEnd, $timezone, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["analytics-rebuild:{$professionalId}"]);

            DB::table('analytics.site_metrics_hourly')
                ->where('professional_id', $professionalId)
                ->where('hour_start', $hour)
                ->delete();

            $visits = DB::table('analytics.site_visits as v')
                ->where('v.professional_id', $professionalId)
                ->where('v.occurred_at', '>=', $hour)
                ->where('v.occurred_at', '<', $hourEnd)
                ->select([
                    'v.site_id',
                    DB::raw('COUNT(*) as visits_count'),
                    DB::raw('COUNT(DISTINCT COALESCE(v.visitor_id::text, v.ip_hash)) as unique_visitors'),
                ])
                ->groupBy('v.site_id')
                ->get();

            $clicks = DB::table('analytics.link_clicks as c')
                ->where('c.professional_id', $professionalId)
                ->where('c.occurred_at', '>=', $hour)
                ->where('c.occurred_at', '<', $hourEnd)
                ->select([
                    'c.site_id',
                    DB::raw('COUNT(*) as clicks_count'),
                    DB::raw('COUNT(DISTINCT COALESCE(c.visitor_id::text, c.ip_hash)) as unique_clickers'),
                ])
                ->groupBy('c.site_id')
                ->get();

            $map = [];

            foreach ($visits as $row) {
                $siteId = (string) $row->site_id;
                $map[$siteId] = [
                    'visits_count' => (int) ($row->visits_count ?? 0),
                    'unique_visitors' => (int) ($row->unique_visitors ?? 0),
                    'clicks_count' => 0,
                    'unique_clickers' => 0,
                ];
            }

            foreach ($clicks as $row) {
                $siteId = (string) $row->site_id;
                $existing = $map[$siteId] ?? [
                    'visits_count' => 0,
                    'unique_visitors' => 0,
                    'clicks_count' => 0,
                    'unique_clickers' => 0,
                ];

                $existing['clicks_count'] = (int) ($row->clicks_count ?? 0);
                $existing['unique_clickers'] = (int) ($row->unique_clickers ?? 0);
                $map[$siteId] = $existing;
            }

            if ($map === []) {
                return;
            }

            $inserts = [];
            foreach ($map as $siteId => $row) {
                $inserts[] = [
                    'hour_start' => $hour,
                    'professional_id' => $professionalId,
                    'site_id' => $siteId,
                    'timezone' => $timezone,
                    'visits_count' => (int) $row['visits_count'],
                    'unique_visitors' => (int) $row['unique_visitors'],
                    'clicks_count' => (int) $row['clicks_count'],
                    'unique_clickers' => (int) $row['unique_clickers'],
                    'updated_at' => $now,
                ];
            }

            DB::table('analytics.site_metrics_hourly')->insert($inserts);
        });
    }

    public function rebuildProfessionalDay(string $professionalId, string $day): void
    {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $day = Carbon::parse($day)->toDateString();
        $timezone = $this->professionalTimezone($professionalId);
        $utcFrom = Carbon::parse($day, $timezone)->startOfDay()->utc();
        $utcTo = Carbon::parse($day, $timezone)->endOfDay()->utc();
        $now = now();

        DB::transaction(function () use ($professionalId, $day, $timezone, $now, $utcFrom, $utcTo): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["analytics-rebuild:{$professionalId}"]);

            DB::table('analytics.site_metrics_daily')
                ->where('professional_id', $professionalId)
                ->where('day', $day)
                ->delete();

            $visits = DB::table('analytics.site_visits as v')
                ->where('v.professional_id', $professionalId)
                ->whereBetween('v.occurred_at', [$utcFrom, $utcTo])
                ->select([
                    'v.site_id',
                    DB::raw('COUNT(*) as visits_count'),
                    DB::raw('COUNT(DISTINCT COALESCE(v.visitor_id::text, v.ip_hash)) as unique_visitors'),
                ])
                ->groupBy('v.site_id')
                ->get();

            $clicks = DB::table('analytics.link_clicks as c')
                ->where('c.professional_id', $professionalId)
                ->whereBetween('c.occurred_at', [$utcFrom, $utcTo])
                ->select([
                    'c.site_id',
                    DB::raw('COUNT(*) as clicks_count'),
                    DB::raw('COUNT(DISTINCT COALESCE(c.visitor_id::text, c.ip_hash)) as unique_clickers'),
                ])
                ->groupBy('c.site_id')
                ->get();

            $map = [];

            foreach ($visits as $row) {
                $siteId = (string) $row->site_id;
                $map[$siteId] = [
                    'visits_count' => (int) ($row->visits_count ?? 0),
                    'unique_visitors' => (int) ($row->unique_visitors ?? 0),
                    'clicks_count' => 0,
                    'unique_clickers' => 0,
                ];
            }

            foreach ($clicks as $row) {
                $siteId = (string) $row->site_id;
                $existing = $map[$siteId] ?? [
                    'visits_count' => 0,
                    'unique_visitors' => 0,
                    'clicks_count' => 0,
                    'unique_clickers' => 0,
                ];

                $existing['clicks_count'] = (int) ($row->clicks_count ?? 0);
                $existing['unique_clickers'] = (int) ($row->unique_clickers ?? 0);
                $map[$siteId] = $existing;
            }

            if ($map === []) {
                return;
            }

            $inserts = [];
            foreach ($map as $siteId => $row) {
                $inserts[] = [
                    'day' => $day,
                    'professional_id' => $professionalId,
                    'site_id' => $siteId,
                    'timezone' => $timezone,
                    'visits_count' => (int) $row['visits_count'],
                    'unique_visitors' => (int) $row['unique_visitors'],
                    'clicks_count' => (int) $row['clicks_count'],
                    'unique_clickers' => (int) $row['unique_clickers'],
                    'updated_at' => $now,
                ];
            }

            DB::table('analytics.site_metrics_daily')->insert($inserts);
        });
    }
}
