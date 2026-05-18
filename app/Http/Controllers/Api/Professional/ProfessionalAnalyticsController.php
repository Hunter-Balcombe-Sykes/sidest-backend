<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

// NOTE: keep shopSummary in sync with summary()'s date-range parsing logic —
// both accept the same ?days / ?from / ?to / ?group_by query params.

// V3: Commerce KPIs now read from commerce.orders (Phase 3). Site visit analytics unchanged.
class ProfessionalAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(private CacheLockService $cacheLock) {}

    public function summary(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));
        $groupBy = mb_strtolower(trim((string) $request->query('group_by', 'day')));
        $forceHourly = $groupBy === 'hour';

        $fromParam = $request->query('from');
        $toParam = $request->query('to');

        try {
            if ($forceHourly && ! $fromParam && ! $toParam) {
                $to = Carbon::now()->utc();
                $from = $to->copy()->subHours(24)->startOfHour();
            } elseif ($fromParam || $toParam) {
                $from = $fromParam
                    ? Carbon::parse($fromParam)->startOfDay()
                    : Carbon::now()->subDays($days)->startOfDay();

                $to = $toParam
                    ? Carbon::parse($toParam)->endOfDay()
                    : Carbon::now()->endOfDay();
            } else {
                $to = Carbon::now()->endOfDay();
                $from = Carbon::now()->subDays($days)->startOfDay();
            }
        } catch (Throwable $e) {
            return $this->error(
                'Invalid date range.  Use YYYY-MM-DD for from/to.',
                422,
                [
                    'from' => $fromParam ? ['Invalid date.'] : [],
                    'to' => $toParam ? ['Invalid date.'] : [],
                ]
            );
        }

        if ($from->gt($to)) {
            return $this->error('Invalid date range: from must be before to. ', 422);
        }

        if ($from->diffInDays($to) > 365) {
            return $this->error('Date range cannot exceed 365 days.', 422);
        }

        $site = $professional->site;
        if (! $site) {
            return $this->error('professional has no site.', 404);
        }

        $professionalTimezone = trim((string) ($professional->timezone ?? '')) ?: 'UTC';
        $hourlyCutoff = now()->utc()->subHours(24);
        $useHourlyBuckets = $forceHourly || (
            $from->copy()->utc()->gte($hourlyCutoff)
            && $to->copy()->utc()->lte(now()->utc()->addMinute())
        );

        // Version token lets invalidateAnalytics() bust ALL summary keys for this
        // professional at once, regardless of date-range or granularity suffix.
        $summaryVersion = (int) Cache::get(
            CacheKeyGenerator::analyticsSummaryVersion($professional->id),
            0
        );

        // q3 shape: commerce data now from commerce.orders (not commission_movements)
        $cacheKey = CacheKeyGenerator::analyticsSummary(
            $professional->id,
            $from->format('YmdH'),
            $to->format('YmdH')
        ).':'.($useHourlyBuckets ? 'hour' : 'day').":v{$summaryVersion}";

        // Cache for 5 minutes (or longer for historical data)
        // Int seconds (not Carbon) so CacheLockService applies ±20% jitter on
        // write — without it, every dashboard cache fills at the same moment
        // and expires at the same instant, hammering the DB on each rollover.
        $cacheTTL = $to->isToday() ? 300 : 86400;

        $data = $this->cacheLock->rememberLocked($cacheKey, $cacheTTL, function () use ($professional, $from, $to, $site, $professionalTimezone, $useHourlyBuckets) {
            // All your existing query logic here

            // Totals (visits)
            $visitsAgg = DB::table('analytics.site_visits')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_visits')
                ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_visitors')
                ->selectRaw('MAX(occurred_at) as last_visit_at')
                ->first();

            // Defaults ensure page-view analytics still works if click analytics queries fail.
            $clicksAgg = (object) [
                'total_clicks' => 0,
                'unique_clickers' => 0,
                'last_click_at' => null,
            ];
            $clicksByDay = collect();
            $topLinks = collect();
            $topSections = collect();

            try {
                $clicksAgg = DB::table('analytics.link_clicks')
                    ->where('professional_id', $professional->id)
                    ->whereBetween('occurred_at', [$from, $to])
                    ->selectRaw('COUNT(*) as total_clicks')
                    ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_clickers')
                    ->selectRaw('MAX(occurred_at) as last_click_at')
                    ->first();
            } catch (QueryException) {
                $clicksAgg = (object) [
                    'total_clicks' => 0,
                    'unique_clickers' => 0,
                    'last_click_at' => null,
                ];
            }

            $totalVisits = (int) ($visitsAgg->total_visits ?? 0);
            $totalClicks = (int) ($clicksAgg->total_clicks ?? 0);

            // Daily charts (unique visitors/clickers) — raw events only, no aggregate tables
            if ($useHourlyBuckets) {
                $driver = DB::connection('pgsql')->getDriverName();
                $truncExpr = $driver === 'sqlite'
                    ? "strftime('%Y-%m-%d %H:00:00', occurred_at)"
                    : "DATE_TRUNC('hour', occurred_at)";

                $visitsByDay = DB::table('analytics.site_visits')
                    ->where('professional_id', $professional->id)
                    ->whereBetween('occurred_at', [$from, $to])
                    ->selectRaw("{$truncExpr} as day, COUNT(DISTINCT COALESCE(visitor_id, ip_hash)) as count")
                    ->groupByRaw($truncExpr)
                    ->orderBy('day')
                    ->get();

                try {
                    $clicksByDay = DB::table('analytics.link_clicks')
                        ->where('professional_id', $professional->id)
                        ->whereBetween('occurred_at', [$from, $to])
                        ->selectRaw("{$truncExpr} as day, COUNT(DISTINCT COALESCE(visitor_id, ip_hash)) as count")
                        ->groupByRaw($truncExpr)
                        ->orderBy('day')
                        ->get();
                } catch (QueryException) {
                    $clicksByDay = collect();
                }
            } else {
                $visitsByDay = DB::table('analytics.site_visits')
                    ->where('professional_id', $professional->id)
                    ->whereBetween('occurred_at', [$from, $to])
                    ->selectRaw('DATE(occurred_at) as day, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as count')
                    ->groupByRaw('DATE(occurred_at)')
                    ->orderBy('day')
                    ->get();

                try {
                    $clicksByDay = DB::table('analytics.link_clicks')
                        ->where('professional_id', $professional->id)
                        ->whereBetween('occurred_at', [$from, $to])
                        ->selectRaw('DATE(occurred_at) as day, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as count')
                        ->groupByRaw('DATE(occurred_at)')
                        ->orderBy('day')
                        ->get();
                } catch (QueryException) {
                    $clicksByDay = collect();
                }
            }

            // Device breakdown (unique visitors)
            $deviceCase = "
                CASE
                    WHEN device_type = 'desktop' THEN 'desktop'
                    WHEN device_type IN ('mobile','tablet') THEN 'mobile'
                    ELSE 'other'
                END
            ";

            $deviceBreakdownRaw = DB::table('analytics.site_visits')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw("$deviceCase as device, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as visitors")
                ->groupByRaw($deviceCase)
                ->get()
                ->keyBy('device');

            $devices = [
                'desktop' => (int) ($deviceBreakdownRaw->get('desktop')?->visitors ?? 0),
                'mobile' => (int) ($deviceBreakdownRaw->get('mobile')?->visitors ?? 0),
                'other' => (int) ($deviceBreakdownRaw->get('other')?->visitors ?? 0),
            ];

            $visitsByDayByDevice = DB::table('analytics.site_visits')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw("DATE(occurred_at) as day, $deviceCase as device, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as count")
                ->groupByRaw("DATE(occurred_at), $deviceCase")
                ->orderBy('day')
                ->get();

            // Countries (unique visitors)
            $countriesRaw = DB::table('analytics.site_visits')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw("COALESCE(country_code, 'UN') as country_code, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as visitors")
                ->groupByRaw("COALESCE(country_code, 'UN')")
                ->orderByDesc('visitors')
                ->get();

            $topCountries = $countriesRaw->take(4)->values();
            $otherCount = (int) $countriesRaw->slice(4)->sum('visitors');

            $countries = $topCountries->map(fn ($r) => [
                'country_code' => $r->country_code,
                'visitors' => (int) $r->visitors,
            ])->all();

            if ($otherCount > 0) {
                $countries[] = ['country_code' => 'OTHER', 'visitors' => $otherCount];
            }

            // Referrer/source breakdown (unique visitors)
            $sourceCase = "
                CASE
                    WHEN COALESCE(utm_source,'') ILIKE 'instagram%' OR COALESCE(referrer,'') ILIKE '%instagram.com%' OR COALESCE(referrer,'') ILIKE '%l.instagram.com%' THEN 'Instagram'
                    WHEN COALESCE(utm_source,'') ILIKE 'facebook%'  OR COALESCE(referrer,'') ILIKE '%facebook.com%'  OR COALESCE(referrer,'') ILIKE '%lm.facebook.com%'  OR COALESCE(referrer,'') ILIKE '%l.facebook.com%' THEN 'Facebook'
                    WHEN COALESCE(utm_source,'') ILIKE 'tiktok%'    OR COALESCE(referrer,'') ILIKE '%tiktok.com%'    THEN 'TikTok'
                    WHEN COALESCE(utm_source,'') ILIKE 'youtube%'   OR COALESCE(referrer,'') ILIKE '%youtube.com%'   OR COALESCE(referrer,'') ILIKE '%youtu.be%' THEN 'YouTube'
                    WHEN COALESCE(utm_source,'') ILIKE 'twitter%'   OR COALESCE(utm_source,'') ILIKE 'x%'          OR COALESCE(referrer,'') ILIKE '%twitter.com%' OR COALESCE(referrer,'') ILIKE '%t.co%' OR COALESCE(referrer,'') ILIKE '%x.com%' THEN 'X (Twitter)'
                    WHEN COALESCE(utm_source,'') ILIKE 'linkedin%'  OR COALESCE(referrer,'') ILIKE '%linkedin.com%' THEN 'LinkedIn'
                    WHEN COALESCE(utm_source,'') ILIKE 'snapchat%'  OR COALESCE(referrer,'') ILIKE '%snapchat.com%' OR COALESCE(referrer,'') ILIKE '%sc.link%' THEN 'Snapchat'
                    WHEN COALESCE(utm_source,'') ILIKE 'pinterest%' OR COALESCE(referrer,'') ILIKE '%pinterest.%'  THEN 'Pinterest'
                    WHEN COALESCE(utm_source,'') ILIKE 'reddit%'    OR COALESCE(referrer,'') ILIKE '%reddit.com%'   THEN 'Reddit'
                    WHEN COALESCE(utm_source,'') ILIKE 'google%'    OR COALESCE(referrer,'') ILIKE '%google.%'      THEN 'Organic (Google)'
                    WHEN COALESCE(utm_source,'') ILIKE 'bing%'      OR COALESCE(referrer,'') ILIKE '%bing.com%'     THEN 'Organic (Bing)'
                    WHEN COALESCE(utm_source,'') ILIKE 'duckduckgo%' OR COALESCE(referrer,'') ILIKE '%duckduckgo.com%' THEN 'Organic (DuckDuckGo)'
                    WHEN COALESCE(utm_source,'') ILIKE 'yahoo%'     OR COALESCE(referrer,'') ILIKE '%search.yahoo.com%' THEN 'Organic (Yahoo)'
                    WHEN referrer IS NULL OR referrer = '' THEN 'Direct Link'
                    ELSE 'Other'
                END
            ";

            $referrersRaw = DB::table('analytics.site_visits')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw("$sourceCase as source, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as visitors")
                ->groupByRaw($sourceCase)
                ->orderByDesc('visitors')
                ->get()
                ->keyBy('source');

            $referrers = [
                ['label' => 'Organic (Google)',     'visitors' => (int) ($referrersRaw->get('Organic (Google)')?->visitors ?? 0)],
                ['label' => 'Organic (Bing)',       'visitors' => (int) ($referrersRaw->get('Organic (Bing)')?->visitors ?? 0)],
                ['label' => 'Organic (DuckDuckGo)', 'visitors' => (int) ($referrersRaw->get('Organic (DuckDuckGo)')?->visitors ?? 0)],
                ['label' => 'Organic (Yahoo)',      'visitors' => (int) ($referrersRaw->get('Organic (Yahoo)')?->visitors ?? 0)],
                ['label' => 'Instagram',            'visitors' => (int) ($referrersRaw->get('Instagram')?->visitors ?? 0)],
                ['label' => 'Facebook',             'visitors' => (int) ($referrersRaw->get('Facebook')?->visitors ?? 0)],
                ['label' => 'TikTok',               'visitors' => (int) ($referrersRaw->get('TikTok')?->visitors ?? 0)],
                ['label' => 'YouTube',              'visitors' => (int) ($referrersRaw->get('YouTube')?->visitors ?? 0)],
                ['label' => 'X (Twitter)',          'visitors' => (int) ($referrersRaw->get('X (Twitter)')?->visitors ?? 0)],
                ['label' => 'LinkedIn',             'visitors' => (int) ($referrersRaw->get('LinkedIn')?->visitors ?? 0)],
                ['label' => 'Snapchat',             'visitors' => (int) ($referrersRaw->get('Snapchat')?->visitors ?? 0)],
                ['label' => 'Pinterest',            'visitors' => (int) ($referrersRaw->get('Pinterest')?->visitors ?? 0)],
                ['label' => 'Reddit',               'visitors' => (int) ($referrersRaw->get('Reddit')?->visitors ?? 0)],
                ['label' => 'Direct Link',          'visitors' => (int) ($referrersRaw->get('Direct Link')?->visitors ?? 0)],
                ['label' => 'Other',                'visitors' => (int) ($referrersRaw->get('Other')?->visitors ?? 0)],
            ];

            try {
                // Top links (total clicks, not unique clickers).
                // platform is pulled from the JSON settings bag so the
                // dashboard can label rows by platform name (instagram,
                // fresha, etc.) rather than raw title/URL.
                $topLinks = DB::table('analytics.link_clicks as lc')
                    ->join('site.blocks as b', 'b.id', '=', 'lc.link_block_id')
                    ->where('lc.professional_id', $professional->id)
                    ->whereBetween('lc.occurred_at', [$from, $to])
                    ->whereNull('b.deleted_at')
                    ->whereRaw("LOWER(COALESCE(b.block_group, '')) = 'links'")
                    ->whereRaw("LOWER(COALESCE(b.block_type, '')) = 'link'")
                    ->selectRaw("b.id as block_id, b.title, b.url, b.settings->>'platform' as platform, b.settings->>'category' as category, COUNT(*) as clicks")
                    ->groupByRaw("b.id, b.title, b.url, b.settings->>'platform', b.settings->>'category'")
                    ->orderByDesc('clicks')
                    ->limit(10)
                    ->get();
            } catch (QueryException) {
                $topLinks = collect();
            }

            try {
                // Top sections (total opens)
                $topSections = DB::table('analytics.link_clicks as lc')
                    ->join('site.blocks as b', 'b.id', '=', 'lc.link_block_id')
                    ->where('lc.professional_id', $professional->id)
                    ->whereBetween('lc.occurred_at', [$from, $to])
                    ->whereNull('b.deleted_at')
                    ->whereRaw("LOWER(COALESCE(b.block_group, '')) = 'sections'")
                    ->whereRaw("LOWER(COALESCE(b.block_type, '')) IN ('gallery', 'services', 'shop', 'booking')")
                    ->selectRaw("LOWER(COALESCE(b.block_type, '')) as section_key, COUNT(*) as clicks")
                    ->groupByRaw("LOWER(COALESCE(b.block_type, ''))")
                    ->orderByDesc('clicks')
                    ->get()
                    ->map(function ($entry) {
                        $sectionKey = (string) $entry->section_key;
                        $title = match ($sectionKey) {
                            'gallery' => 'Gallery of Work',
                            'services' => 'Services & Pricing',
                            'shop' => 'Shop',
                            'booking' => 'Booking',
                            default => ucfirst($sectionKey),
                        };

                        return [
                            'key' => $sectionKey,
                            'title' => $title,
                            'clicks' => (int) ($entry->clicks ?? 0),
                        ];
                    })
                    ->values();
            } catch (QueryException) {
                $topSections = collect();
            }

            $ctr = $totalVisits > 0 ? round(($totalClicks / $totalVisits) * 100, 2) : 0.0;

            // ── Commerce + funnel aggregations ──────────────────────────────────
            // Single source for shop-page widgets: orders / cart adds / commission /
            // revenue + KPI cards with vs-prior-period change badges. Mirrored
            // window of equal length running up to $from gives the comparison set.
            $commerceCurrent = $this->commerceAggregates($professional->id, $from, $to);

            $duration = $from->diffInSeconds($to);
            $prevTo = $from->copy()->subSecond();
            $prevFrom = $prevTo->copy()->subSeconds($duration);
            $commercePrevious = $this->commerceAggregates($professional->id, $prevFrom, $prevTo);

            $visitsPrevious = (int) DB::table('analytics.site_visits')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$prevFrom, $prevTo])
                ->count();

            $clicksPrevious = 0;
            try {
                $clicksPrevious = (int) DB::table('analytics.link_clicks')
                    ->where('professional_id', $professional->id)
                    ->whereBetween('occurred_at', [$prevFrom, $prevTo])
                    ->count();
            } catch (QueryException) {
            }

            $commerceCharts = $this->commerceCharts($professional->id, $from, $to, $useHourlyBuckets);
            $topProducts = $this->topProducts($professional->id, $from, $to);

            return [
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'granularity' => $useHourlyBuckets ? 'hour' : 'day',
                'bucket_timezone' => $useHourlyBuckets ? 'UTC' : $professionalTimezone,
                'professional' => [
                    'id' => $professional->id,
                    'handle' => $professional->handle,
                    'display_name' => $professional->display_name,
                ],
                'site' => [
                    'id' => $site->id,
                    'subdomain' => $site->subdomain,
                    'published' => (bool) $site->is_published,
                ],
                'breakdowns' => [
                    'devices' => $devices,
                    'countries' => $countries,
                    'referrers' => $referrers,
                ],
                'totals' => [
                    'visits' => $totalVisits,
                    'unique_visitors' => (int) ($visitsAgg->unique_visitors ?? 0),
                    'clicks' => $totalClicks,
                    'unique_clickers' => (int) ($clicksAgg->unique_clickers ?? 0),
                    'ctr_percent' => $ctr,
                    'last_visit_at' => $visitsAgg->last_visit_at ? Carbon::parse($visitsAgg->last_visit_at)->toISOString() : null,
                    'last_click_at' => $clicksAgg->last_click_at ? Carbon::parse($clicksAgg->last_click_at)->toISOString() : null,
                    // Commerce KPIs — orders/commission/revenue/cart_adds/avg metrics for the period
                    'orders' => $commerceCurrent['orders'],
                    'cart_adds' => $commerceCurrent['cart_adds'],
                    'checkouts' => $commerceCurrent['checkouts'],
                    'commission_cents' => $commerceCurrent['commission_cents'],
                    'revenue_cents' => $commerceCurrent['revenue_cents'],
                    'avg_order_cents' => $commerceCurrent['avg_order_cents'],
                    'avg_order_qty' => $commerceCurrent['avg_order_qty'],
                    // Pre-calculated rates using standard industry formulas — frontend must not recompute these
                    'conversion_rate_pct' => (function () use ($visitsAgg, $commerceCurrent): ?float {
                        $uniqueVisitors = (int) ($visitsAgg->unique_visitors ?? 0);

                        return $uniqueVisitors > 0
                            ? round($commerceCurrent['orders'] / $uniqueVisitors * 100, 2)
                            : null;
                    })(),
                    'abandoned_cart_rate_pct' => (function () use ($professional, $from, $to, $commerceCurrent): ?float {
                        $sessions = $this->checkoutSessions($professional->id, $from, $to);

                        return $sessions > 0
                            ? round(max(0.0, min(100.0, ($sessions - $commerceCurrent['orders']) / $sessions * 100)), 2)
                            : null;
                    })(),
                ],
                // Equal-length prior window for change badges. All-time queries
                // produce a prev-window that pre-dates the account's data — caller
                // is expected to suppress badges in that case.
                'totals_previous' => [
                    'visits' => $visitsPrevious,
                    'clicks' => $clicksPrevious,
                    'orders' => $commercePrevious['orders'],
                    'cart_adds' => $commercePrevious['cart_adds'],
                    'commission_cents' => $commercePrevious['commission_cents'],
                    'revenue_cents' => $commercePrevious['revenue_cents'],
                    'avg_order_cents' => $commercePrevious['avg_order_cents'],
                    'avg_order_qty' => $commercePrevious['avg_order_qty'],
                ],
                'charts' => [
                    'visits_by_day' => $visitsByDay,
                    'clicks_by_day' => $clicksByDay,
                    'visits_by_day_by_device' => $visitsByDayByDevice,
                    'orders_by_day' => $commerceCharts['orders_by_day'],
                    'commission_by_day' => $commerceCharts['commission_by_day'],
                    'revenue_by_day' => $commerceCharts['revenue_by_day'],
                ],
                'top_sections' => $topSections,
                'top_links' => $topLinks,
                'top_products' => $topProducts,
            ];
        });

        return $this->success($data);
    }

    /**
     * COUNT DISTINCT session_id from checkout_start events for the given window.
     * Used as the abandoned cart rate denominator — consistent with AnalyticsService.
     */
    private function checkoutSessions(string $professionalId, Carbon $from, Carbon $to): int
    {
        return (int) DB::table('analytics.cart_events')
            ->where('professional_id', $professionalId)
            ->where('event_type', 'checkout_start')
            ->whereNotNull('session_id')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to)
            ->distinct()
            ->count('session_id');
    }

    /**
     * Shop analytics funnel for the authenticated professional (as affiliate).
     *
     * @deprecated Data is now folded into summary() — kept temporarily for in-flight callers.
     */
    public function shopSummary(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));
        $groupBy = mb_strtolower(trim((string) $request->query('group_by', 'day')));
        $forceHourly = $groupBy === 'hour';

        $fromParam = $request->query('from');
        $toParam = $request->query('to');

        try {
            if ($forceHourly && ! $fromParam && ! $toParam) {
                $to = Carbon::now()->utc();
                $from = $to->copy()->subHours(24)->startOfHour();
            } elseif ($fromParam || $toParam) {
                $from = $fromParam
                    ? Carbon::parse($fromParam)->startOfDay()
                    : Carbon::now()->subDays($days)->startOfDay();
                $to = $toParam
                    ? Carbon::parse($toParam)->endOfDay()
                    : Carbon::now()->endOfDay();
            } else {
                $to = Carbon::now()->endOfDay();
                $from = Carbon::now()->subDays($days)->startOfDay();
            }
        } catch (Throwable) {
            return $this->error('Invalid date range.', 422);
        }

        if ($from->gt($to)) {
            return $this->error('Invalid date range: from must be before to.', 422);
        }

        if ($from->diffInDays($to) > 365) {
            return $this->error('Date range cannot exceed 365 days.', 422);
        }

        $site = $professional->site;
        if (! $site) {
            return $this->error('Professional has no site.', 404);
        }

        $hourlyCutoff = now()->utc()->subHours(24);
        $useHourlyBuckets = $forceHourly || (
            $from->copy()->utc()->gte($hourlyCutoff)
            && $to->copy()->utc()->lte(now()->utc()->addMinute())
        );

        $summaryVersion = (int) Cache::get(
            CacheKeyGenerator::analyticsSummaryVersion($professional->id),
            0
        );

        $cacheKey = 'analytics:shop:'.$professional->id.':'.$from->format('YmdH').':'.$to->format('YmdH').':'
            .($useHourlyBuckets ? 'hour' : 'day').":v{$summaryVersion}";

        // Int seconds (not Carbon) so CacheLockService applies ±20% jitter on
        // write — without it, every dashboard cache fills at the same moment
        // and expires at the same instant, hammering the DB on each rollover.
        $cacheTTL = $to->isToday() ? 300 : 86400;

        $data = $this->cacheLock->rememberLocked($cacheKey, $cacheTTL, function () use ($professional, $from, $to, $useHourlyBuckets) {
            // ── Funnel totals ────────────────────────────────────────────────

            $visitors = (int) DB::table('analytics.site_visits')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->count();

            // Shop opens: clicks on blocks with block_type = 'shop'
            $shopOpens = (int) DB::table('analytics.link_clicks as lc')
                ->join('site.blocks as b', 'b.id', '=', 'lc.link_block_id')
                ->where('lc.professional_id', $professional->id)
                ->whereBetween('lc.occurred_at', [$from, $to])
                ->whereRaw("LOWER(COALESCE(b.block_type, '')) = 'shop'")
                ->count();

            $cartAdds = (int) DB::table('analytics.cart_events')
                ->where('professional_id', $professional->id)
                ->where('event_type', 'cart_add')
                ->whereBetween('occurred_at', [$from, $to])
                ->count();

            $checkouts = (int) DB::table('analytics.cart_events')
                ->where('professional_id', $professional->id)
                ->where('event_type', 'checkout_start')
                ->whereBetween('occurred_at', [$from, $to])
                ->count();

            // Orders attributed to this affiliate from commerce.orders
            $excluded = ['stub', 'cancelled', 'voided', 'refunded'];
            $ordersResult = DB::table('commerce.orders')
                ->where('affiliate_professional_id', $professional->id)
                ->whereNotIn('status', $excluded)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM(commission_cents), 0) as commission_cents')
                ->first();

            $orders = (int) ($ordersResult->orders ?? 0);
            $commissionCents = (int) ($ordersResult->commission_cents ?? 0);

            // ── Orders chart ────────────────────────────────────────────────
            // Groups orders by hour or day for a sparkline / area chart.

            $driver = DB::connection('pgsql')->getDriverName();
            if ($useHourlyBuckets) {
                $bucket = $driver === 'sqlite'
                    ? "strftime('%Y-%m-%d %H:00:00', occurred_at)"
                    : "DATE_TRUNC('hour', occurred_at)";
            } else {
                $bucket = $driver === 'sqlite'
                    ? "strftime('%Y-%m-%d', occurred_at)"
                    : 'DATE(occurred_at)';
            }

            $ordersByBucket = DB::table('commerce.orders')
                ->where('affiliate_professional_id', $professional->id)
                ->whereNotIn('status', $excluded)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw("{$bucket} as bucket, COUNT(*) as orders, COALESCE(SUM(commission_cents), 0) as commission_cents")
                ->groupByRaw($bucket)
                ->orderBy('bucket')
                ->get();

            return [
                'granularity' => $useHourlyBuckets ? 'hour' : 'day',
                'totals' => [
                    'visitors' => $visitors,
                    'shop_opens' => $shopOpens,
                    'cart_adds' => $cartAdds,
                    'checkouts' => $checkouts,
                    'orders' => $orders,
                    'commission_cents' => $commissionCents,
                ],
                'charts' => [
                    'orders_by_day' => $ordersByBucket,
                ],
            ];
        });

        return $this->success($data);
    }

    /**
     * Aggregate commerce KPIs for a window — used for both the current
     * period and the equal-length prior period (vs-prior change badges).
     *
     * Reads commerce.orders (not commission_movements). revenue_cents = gross_cents
     * (pre-discount, post-refund is net_cents — we use gross for "revenue" KPI to match
     * the existing semantics). avg_order_qty aggregated from commerce.order_items.
     *
     * @return array{orders:int, cart_adds:int, checkouts:int, commission_cents:int, revenue_cents:int, avg_order_cents:int, avg_order_qty:float}
     */
    private function commerceAggregates(string $professionalId, Carbon $from, Carbon $to): array
    {
        // Status exclusion: stub/cancelled/voided/refunded — 'approved' is included (it means paid)
        $excluded = ['stub', 'cancelled', 'voided', 'refunded'];

        $cartAdds = (int) DB::table('analytics.cart_events')
            ->where('professional_id', $professionalId)
            ->where('event_type', 'cart_add')
            ->whereBetween('occurred_at', [$from, $to])
            ->count();

        $checkouts = (int) DB::table('analytics.cart_events')
            ->where('professional_id', $professionalId)
            ->where('event_type', 'checkout_start')
            ->whereBetween('occurred_at', [$from, $to])
            ->count();

        $orderAgg = DB::table('commerce.orders')
            ->where('affiliate_professional_id', $professionalId)
            ->whereNotIn('status', $excluded)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('
                COUNT(*) as orders,
                COALESCE(SUM(commission_cents), 0) as commission_cents,
                COALESCE(SUM(gross_cents), 0) as revenue_cents
            ')
            ->first();

        // Total quantity from order_items (normalized mirror of line_items JSONB)
        $qtyAgg = DB::table('commerce.order_items as oi')
            ->join('commerce.orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.affiliate_professional_id', $professionalId)
            ->whereNotIn('o.status', $excluded)
            ->whereBetween('o.occurred_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(oi.quantity), 0) as total_qty')
            ->first();

        $orders = (int) ($orderAgg->orders ?? 0);
        $commissionCents = (int) ($orderAgg->commission_cents ?? 0);
        $revenueCents = (int) ($orderAgg->revenue_cents ?? 0);
        $totalQty = (int) ($qtyAgg->total_qty ?? 0);

        $avgOrderCents = $orders > 0 ? (int) round($revenueCents / $orders) : 0;
        $avgOrderQty = $orders > 0 ? round($totalQty / $orders, 2) : 0.0;

        return [
            'orders' => $orders,
            'cart_adds' => $cartAdds,
            'checkouts' => $checkouts,
            'commission_cents' => $commissionCents,
            'revenue_cents' => $revenueCents,
            'avg_order_cents' => $avgOrderCents,
            'avg_order_qty' => $avgOrderQty,
        ];
    }

    /**
     * Commerce timeseries for the area chart — orders / commission / revenue
     * grouped by hour or day. Reads commerce.orders (not commission_movements).
     *
     * @return array{orders_by_day:\Illuminate\Support\Collection, commission_by_day:\Illuminate\Support\Collection, revenue_by_day:\Illuminate\Support\Collection}
     */
    private function commerceCharts(string $professionalId, Carbon $from, Carbon $to, bool $useHourlyBuckets): array
    {
        $excluded = ['stub', 'cancelled', 'voided', 'refunded'];
        $driver = DB::connection('pgsql')->getDriverName();

        if ($driver === 'sqlite') {
            $bucket = $useHourlyBuckets
                ? "strftime('%Y-%m-%d %H:00:00', occurred_at)"
                : "strftime('%Y-%m-%d', occurred_at)";
        } else {
            $bucket = $useHourlyBuckets
                ? "DATE_TRUNC('hour', occurred_at)"
                : 'DATE(occurred_at)';
        }

        $rows = DB::table('commerce.orders')
            ->where('affiliate_professional_id', $professionalId)
            ->whereNotIn('status', $excluded)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("
                {$bucket} as bucket,
                COUNT(*) as orders,
                COALESCE(SUM(commission_cents), 0) as commission_cents,
                COALESCE(SUM(gross_cents), 0) as revenue_cents
            ")
            ->groupByRaw($bucket)
            ->orderBy('bucket')
            ->get();

        return [
            'orders_by_day' => $rows->map(fn ($r) => [
                'bucket' => $r->bucket,
                'orders' => (int) $r->orders,
            ])->values(),
            'commission_by_day' => $rows->map(fn ($r) => [
                'bucket' => $r->bucket,
                'commission_cents' => (int) $r->commission_cents,
            ])->values(),
            'revenue_by_day' => $rows->map(fn ($r) => [
                'bucket' => $r->bucket,
                'revenue_cents' => (int) $r->revenue_cents,
            ])->values(),
        ];
    }

    /**
     * Top products by commission earned in the window.
     * Reads from commerce.order_items (normalized trigger mirror of line_items JSONB).
     * Joined to commerce.orders to apply affiliate + status filters.
     * Product title comes from order_items.shopify_product_title (set by webhook handler).
     */
    private function topProducts(string $professionalId, Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        $excluded = ['stub', 'cancelled', 'voided', 'refunded'];

        return DB::table('commerce.order_items as oi')
            ->join('commerce.orders as o', 'o.id', '=', 'oi.order_id')
            ->where('o.affiliate_professional_id', $professionalId)
            ->whereNotIn('o.status', $excluded)
            ->whereBetween('o.occurred_at', [$from, $to])
            ->whereNotNull('oi.shopify_product_id')
            ->selectRaw('
                oi.shopify_product_id as product_id,
                MAX(oi.title) as product_title,
                COUNT(DISTINCT o.id) as orders,
                COALESCE(SUM(oi.commission_cents), 0) as commission_cents,
                COALESCE(SUM(oi.line_total_cents), 0) as revenue_cents
            ')
            ->groupBy('oi.shopify_product_id')
            ->orderByRaw('SUM(oi.commission_cents) DESC')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'product_id' => (string) $r->product_id,
                'title' => (string) ($r->product_title ?? 'Product #'.substr((string) $r->product_id, -6)),
                'orders' => (int) $r->orders,
                'commission_cents' => (int) $r->commission_cents,
                'revenue_cents' => (int) $r->revenue_cents,
            ])
            ->values();
    }
}
