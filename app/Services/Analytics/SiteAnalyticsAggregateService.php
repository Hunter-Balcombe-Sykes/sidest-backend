<?php

namespace App\Services\Analytics;

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SiteAnalyticsAggregateService
{
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
                    DB::raw("COUNT(DISTINCT COALESCE(v.visitor_id::text, v.ip_hash)) as unique_visitors"),
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
                    DB::raw("COUNT(DISTINCT COALESCE(c.visitor_id::text, c.ip_hash)) as unique_clickers"),
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
        $now = now();

        DB::transaction(function () use ($professionalId, $day, $timezone, $now): void {
            DB::table('analytics.site_metrics_daily')
                ->where('professional_id', $professionalId)
                ->where('day', $day)
                ->delete();

            $visits = DB::table('analytics.site_visits as v')
                ->where('v.professional_id', $professionalId)
                ->whereRaw('(v.occurred_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
                ->select([
                    'v.site_id',
                    DB::raw('COUNT(*) as visits_count'),
                    DB::raw("COUNT(DISTINCT COALESCE(v.visitor_id::text, v.ip_hash)) as unique_visitors"),
                ])
                ->groupBy('v.site_id')
                ->get();

            $clicks = DB::table('analytics.link_clicks as c')
                ->where('c.professional_id', $professionalId)
                ->whereRaw('(c.occurred_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
                ->select([
                    'c.site_id',
                    DB::raw('COUNT(*) as clicks_count'),
                    DB::raw("COUNT(DISTINCT COALESCE(c.visitor_id::text, c.ip_hash)) as unique_clickers"),
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

    /** @var array<string, string> */
    private array $timezoneCache = [];

    private function professionalTimezone(string $professionalId): string
    {
        if (isset($this->timezoneCache[$professionalId])) {
            return $this->timezoneCache[$professionalId];
        }

        $timezone = Professional::query()
            ->where('id', $professionalId)
            ->value('timezone');

        $timezone = trim((string) $timezone);
        $resolved = $timezone !== '' ? $timezone : 'UTC';

        $this->timezoneCache[$professionalId] = $resolved;

        return $resolved;
    }
}
