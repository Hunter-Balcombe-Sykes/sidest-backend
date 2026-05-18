<?php

namespace App\Services\Analytics;

use App\Models\Commerce\Order;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — Brand + Affiliate analytics read surface.
 *
 * One service, two public methods, full payload across six time windows in a single call.
 * Computed via per-metric "selectRaw + CASE WHEN" queries that compute all six windows in
 * one query each — minimises round trips and stays SQLite-compatible for tests.
 *
 * Source-of-truth split (per plan §Decisions locked):
 *   Orders + commissions → commerce.orders + commerce.brand_affiliate_rollup + commerce.commission_payouts
 *   Views                 → analytics.site_visits
 *   Link clicks           → analytics.link_clicks (+ join to site.blocks for per-section)
 *   Cart sessions         → analytics.cart_events (event_type=checkout_start, distinct session_id)
 *   Section views         → analytics.section_views
 *
 * Cached 5min via CacheLockService::rememberLocked — analytics tolerates 5min lag.
 */
class AnalyticsService
{
    /**
     * The six windows, in display order. Frontend slices by key.
     */
    public const WINDOWS = ['h24', 'd7', 'd30', 'm6', 'y1', 'all'];

    public function __construct(private readonly CacheLockService $cacheLock) {}

    /**
     * @return array<string, mixed>
     */
    public function forAffiliate(Professional $affiliate): array
    {
        return $this->cacheLock->rememberLocked(
            $this->versionedCacheKey('affiliate', $affiliate->id),
            300,
            fn () => $this->computeAffiliate($affiliate),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forBrand(Professional $brand): array
    {
        return $this->cacheLock->rememberLocked(
            $this->versionedCacheKey('brand', $brand->id),
            300,
            fn () => $this->computeBrand($brand),
        );
    }

    /**
     * Build a versioned cache key — embeds the AnalyticsCacheService version counter so
     * any event ingest (pageview/click/cartEvent/sectionSeen) that calls
     * invalidateAnalytics() automatically makes this key unreachable. New writes land in
     * fresh keys with the bumped version, stale keys time out on their own 5min TTL.
     *
     * Trade-off: a brand's stats don't auto-bust on an affiliate's event (affiliate's
     * version bumps, brand's doesn't). Brand stats then lag up to 5min after affiliate
     * activity — acceptable per the locked decision that "analytics tolerates 5min lag".
     */
    private function versionedCacheKey(string $role, string $professionalId): string
    {
        $version = Cache::get(CacheKeyGenerator::analyticsSummaryVersion($professionalId), 0);

        return "analytics:{$role}:{$professionalId}:v{$version}";
    }

    private function computeAffiliate(Professional $affiliate): array
    {
        $bounds = $this->windowBounds();

        $views = $this->windowedSum('analytics.site_visits', 'occurred_at', 'COUNT(*)', $bounds, [
            'professional_id' => $affiliate->id,
        ]);

        $uniqueVisitors = $this->windowedDistinctCount('analytics.site_visits', 'visitor_id', $bounds, [
            'professional_id' => $affiliate->id,
        ]);

        $orders = $this->windowedSum('commerce.orders', 'occurred_at', 'COUNT(*)', $bounds, [
            'affiliate_professional_id' => $affiliate->id,
        ], excludeStatuses: Order::EXCLUDED_FROM_AGGREGATES);

        $sales = $this->windowedSum('commerce.orders', 'occurred_at', 'SUM(gross_cents)', $bounds, [
            'affiliate_professional_id' => $affiliate->id,
        ], excludeStatuses: Order::EXCLUDED_FROM_AGGREGATES);

        $commissions = $this->windowedSum('commerce.orders', 'occurred_at', 'SUM(commission_cents)', $bounds, [
            'affiliate_professional_id' => $affiliate->id,
        ], excludeStatuses: Order::EXCLUDED_FROM_AGGREGATES);

        $refunds = $this->windowedSum('commerce.orders', 'occurred_at', 'SUM(refund_cents)', $bounds, [
            'affiliate_professional_id' => $affiliate->id,
        ], excludeStatuses: Order::EXCLUDED_FROM_AGGREGATES);

        $cartSessions = $this->windowedCartSessions($affiliate->id, $bounds);

        // Conversion rate = orders / views * 100. Abandoned cart rate = (sessions - orders) / sessions * 100.
        // Both computed per window from the raw counts above; the rates aren't stored, just derived.
        $conversionRate = $this->computeRate($orders, $uniqueVisitors, multiplier: 100);
        $abandonedCart = $this->computeAbandonedCartRate($cartSessions, $orders);

        return [
            'views' => $views,
            'unique_visitors' => $uniqueVisitors,
            'orders_count' => $orders,
            'total_sales_cents' => $sales,
            'total_commissions_cents' => $commissions,
            'total_refunds_cents' => $refunds,
            'cart_sessions' => $cartSessions,
            'conversion_rate_pct' => $conversionRate,
            'abandoned_cart_rate_pct' => $abandonedCart,
            'pending_commission_cents' => $this->affiliatePendingCommissionCents($affiliate->id),
            'commissions_pocketed_cents' => $this->windowedCommissionsPocketed($affiliate->id, $bounds),
            'top_referrers' => $this->affiliateTopReferrers($affiliate->id),
            'per_section_clicks' => $this->affiliatePerSectionClicks($affiliate->id),
            'per_section_views' => $this->affiliatePerSectionViews($affiliate->id),
            'per_platform_clicks' => $this->affiliatePerPlatformClicks($affiliate->id),
            'best_selling_products' => $this->affiliateBestSellingProducts($affiliate->id),
        ];
    }

    private function computeBrand(Professional $brand): array
    {
        $bounds = $this->windowBounds();
        $brandId = $brand->id;

        $orders = $this->windowedSum('commerce.orders', 'occurred_at', 'COUNT(*)', $bounds, [
            'brand_professional_id' => $brandId,
        ], excludeStatuses: Order::EXCLUDED_FROM_AGGREGATES);

        $sales = $this->windowedSum('commerce.orders', 'occurred_at', 'SUM(gross_cents)', $bounds, [
            'brand_professional_id' => $brandId,
        ], excludeStatuses: Order::EXCLUDED_FROM_AGGREGATES);

        $commissions = $this->windowedSum('commerce.orders', 'occurred_at', 'SUM(commission_cents)', $bounds, [
            'brand_professional_id' => $brandId,
        ], excludeStatuses: Order::EXCLUDED_FROM_AGGREGATES);

        // Brand views = sum of site_visits across all affiliates with this brand selected.
        // commerce.orders carries affiliate_professional_id for orders; for visits we need to
        // know which affiliates promote this brand. Affiliate-brand mapping lives on
        // commerce.affiliate_product_selections (an affiliate selects products from a brand).
        $brandViews = $this->brandWindowedViews($brandId, $bounds);

        $cartSessions = $this->brandWindowedCartSessions($brandId, $bounds);
        $brandUniqueVisitors = $this->brandWindowedUniqueVisitors($brandId, $bounds);

        $avgConversionRate = $this->computeRate($orders, $brandUniqueVisitors, multiplier: 100);
        $avgAbandonedCart = $this->computeAbandonedCartRate($cartSessions, $orders);

        return [
            'total_orders' => $orders,
            'total_sales_cents' => $sales,
            'total_commissions_cents' => $commissions,
            'total_views' => $brandViews,
            'unique_visitors' => $brandUniqueVisitors,
            'cart_sessions' => $cartSessions,
            'avg_conversion_rate_pct' => $avgConversionRate,
            'avg_abandoned_cart_rate_pct' => $avgAbandonedCart,
            'top_affiliates' => $this->brandTopAffiliates($brandId),
            'total_refunds_cents' => $this->windowedSum('commerce.orders', 'occurred_at', 'SUM(refund_cents)', $bounds, [
                'brand_professional_id' => $brandId,
            ], excludeStatuses: Order::EXCLUDED_FROM_AGGREGATES),
        ];
    }

    /**
     * Compute the six window-start timestamps. h24 is rolling (now - 24h); d7/d30/m6/y1 are
     * calendar (startOfDay of now - N days, inclusive); all is null (no filter).
     *
     * @return array{h24: string, d7: string, d30: string, m6: string, y1: string, all: ?string}
     */
    private function windowBounds(): array
    {
        $now = Carbon::now();

        return [
            'h24' => $now->copy()->subHours(24)->toDateTimeString(),
            'd7' => $now->copy()->subDays(6)->startOfDay()->toDateTimeString(),
            'd30' => $now->copy()->subDays(29)->startOfDay()->toDateTimeString(),
            'm6' => $now->copy()->subDays(179)->startOfDay()->toDateTimeString(),
            'y1' => $now->copy()->subDays(364)->startOfDay()->toDateTimeString(),
            'all' => null,
        ];
    }

    /**
     * Single query that returns the chosen aggregate across all six windows.
     *
     * @param  array<string, ?string>  $bounds
     * @param  array<string, mixed>  $where
     * @param  array<int, string>  $excludeStatuses
     * @return array<string, int>
     */
    private function windowedSum(
        string $table,
        string $timeColumn,
        string $aggregate,
        array $bounds,
        array $where,
        array $excludeStatuses = [],
    ): array {
        $h24 = $bounds['h24'];
        $d7 = $bounds['d7'];
        $d30 = $bounds['d30'];
        $m6 = $bounds['m6'];
        $y1 = $bounds['y1'];

        $query = DB::table($table);
        foreach ($where as $col => $val) {
            $query->where($col, $val);
        }
        if (! empty($excludeStatuses)) {
            $query->whereNotIn('status', $excludeStatuses);
        }

        // SUM(CASE ...) for non-COUNT aggregates needs the inner aggregate adapted.
        // For 'COUNT(*)' we use SUM(CASE WHEN ... THEN 1 ELSE 0 END).
        // For 'SUM(col)' we use SUM(CASE WHEN ... THEN col ELSE 0 END).
        $inner = $this->innerExpressionFor($aggregate);

        $row = $query->selectRaw("
            COALESCE(SUM(CASE WHEN {$timeColumn} >= ? THEN {$inner} ELSE 0 END), 0) AS h24,
            COALESCE(SUM(CASE WHEN {$timeColumn} >= ? THEN {$inner} ELSE 0 END), 0) AS d7,
            COALESCE(SUM(CASE WHEN {$timeColumn} >= ? THEN {$inner} ELSE 0 END), 0) AS d30,
            COALESCE(SUM(CASE WHEN {$timeColumn} >= ? THEN {$inner} ELSE 0 END), 0) AS m6,
            COALESCE(SUM(CASE WHEN {$timeColumn} >= ? THEN {$inner} ELSE 0 END), 0) AS y1,
            COALESCE({$aggregate}, 0) AS all_time
        ", [$h24, $d7, $d30, $m6, $y1])->first();

        return [
            'h24' => (int) ($row->h24 ?? 0),
            'd7' => (int) ($row->d7 ?? 0),
            'd30' => (int) ($row->d30 ?? 0),
            'm6' => (int) ($row->m6 ?? 0),
            'y1' => (int) ($row->y1 ?? 0),
            'all' => (int) ($row->all_time ?? 0),
        ];
    }

    /**
     * Convert an outer aggregate (COUNT(*) / SUM(col)) into the inner CASE expression
     * (1 / col respectively).
     */
    private function innerExpressionFor(string $aggregate): string
    {
        $normalized = strtolower(trim($aggregate));
        if ($normalized === 'count(*)') {
            return '1';
        }
        // Expect 'SUM(col)' — extract the column.
        if (preg_match('/^sum\(([a-z0-9_]+)\)$/i', $normalized, $m) === 1) {
            return $m[1];
        }

        return '1';
    }

    /**
     * COUNT DISTINCT across the six windows — one query per window because
     * COUNT(DISTINCT ...) doesn't compose into a CASE WHEN aggregate.
     *
     * @param  array<string, ?string>  $bounds
     * @param  array<string, mixed>  $where
     * @return array<string, int>
     */
    private function windowedDistinctCount(string $table, string $distinctColumn, array $bounds, array $where): array
    {
        $result = [];
        foreach (self::WINDOWS as $w) {
            $query = DB::table($table)->whereNotNull($distinctColumn);
            foreach ($where as $col => $val) {
                $query->where($col, $val);
            }
            if ($bounds[$w] !== null) {
                $query->where('occurred_at', '>=', $bounds[$w]);
            }
            $result[$w] = (int) $query->distinct()->count($distinctColumn);
        }

        return $result;
    }

    /**
     * Cart sessions = COUNT DISTINCT session_id from analytics.cart_events with event_type=checkout_start.
     * Need a separate query per window because COUNT DISTINCT doesn't compose into FILTER cleanly.
     *
     * @param  array<string, ?string>  $bounds
     * @return array<string, int>
     */
    private function windowedCartSessions(string $professionalId, array $bounds): array
    {
        $result = [];
        foreach (self::WINDOWS as $w) {
            $query = DB::table('analytics.cart_events')
                ->where('professional_id', $professionalId)
                ->where('event_type', 'checkout_start')
                ->whereNotNull('session_id');
            if ($bounds[$w] !== null) {
                $query->where('occurred_at', '>=', $bounds[$w]);
            }
            $result[$w] = (int) $query->distinct()->count('session_id');
        }

        return $result;
    }

    /**
     * Brand-level cart sessions — sum across all affiliates promoting this brand. Affiliate-brand
     * mapping comes from commerce.brand_affiliate_rollup (which is the post-Stripe-v2 SOT for
     * brand↔affiliate relationships and orders).
     *
     * @param  array<string, ?string>  $bounds
     * @return array<string, int>
     */
    private function brandWindowedCartSessions(string $brandId, array $bounds): array
    {
        $affiliateIds = DB::table('commerce.brand_affiliate_rollup')
            ->where('brand_professional_id', $brandId)
            ->distinct()
            ->pluck('affiliate_professional_id');

        if ($affiliateIds->isEmpty()) {
            return array_fill_keys(self::WINDOWS, 0);
        }

        $result = [];
        foreach (self::WINDOWS as $w) {
            $query = DB::table('analytics.cart_events')
                ->whereIn('professional_id', $affiliateIds)
                ->where('event_type', 'checkout_start')
                ->whereNotNull('session_id');
            if ($bounds[$w] !== null) {
                $query->where('occurred_at', '>=', $bounds[$w]);
            }
            $result[$w] = (int) $query->distinct()->count('session_id');
        }

        return $result;
    }

    /**
     * Brand-level views — sum site_visits across affiliates promoting this brand.
     *
     * @param  array<string, ?string>  $bounds
     * @return array<string, int>
     */
    private function brandWindowedViews(string $brandId, array $bounds): array
    {
        $affiliateIds = DB::table('commerce.brand_affiliate_rollup')
            ->where('brand_professional_id', $brandId)
            ->distinct()
            ->pluck('affiliate_professional_id');

        if ($affiliateIds->isEmpty()) {
            return array_fill_keys(self::WINDOWS, 0);
        }

        $result = [];
        foreach (self::WINDOWS as $w) {
            $query = DB::table('analytics.site_visits')->whereIn('professional_id', $affiliateIds);
            if ($bounds[$w] !== null) {
                $query->where('occurred_at', '>=', $bounds[$w]);
            }
            $result[$w] = (int) $query->count();
        }

        return $result;
    }

    /**
     * Brand-level unique visitors — COUNT DISTINCT visitor_id from site_visits across all
     * affiliates promoting this brand. Same affiliate-set lookup as brandWindowedViews.
     *
     * @param  array<string, ?string>  $bounds
     * @return array<string, int>
     */
    private function brandWindowedUniqueVisitors(string $brandId, array $bounds): array
    {
        $affiliateIds = DB::table('commerce.brand_affiliate_rollup')
            ->where('brand_professional_id', $brandId)
            ->distinct()
            ->pluck('affiliate_professional_id');

        if ($affiliateIds->isEmpty()) {
            return array_fill_keys(self::WINDOWS, 0);
        }

        $result = [];
        foreach (self::WINDOWS as $w) {
            $query = DB::table('analytics.site_visits')
                ->whereIn('professional_id', $affiliateIds)
                ->whereNotNull('visitor_id');
            if ($bounds[$w] !== null) {
                $query->where('occurred_at', '>=', $bounds[$w]);
            }
            $result[$w] = (int) $query->distinct()->count('visitor_id');
        }

        return $result;
    }

    /**
     * Affiliate's commissions actually pocketed (Stripe payouts settled to their account).
     *
     * @param  array<string, ?string>  $bounds
     * @return array<string, int>
     */
    private function windowedCommissionsPocketed(string $affiliateId, array $bounds): array
    {
        return $this->windowedSum(
            'commerce.commission_payouts',
            'created_at',
            'SUM(net_payout_cents)',
            $bounds,
            [
                'affiliate_professional_id' => $affiliateId,
                'status' => 'completed',
            ],
        );
    }

    /**
     * Pending commission isn't windowed — it's a point-in-time total of "money on the way".
     */
    private function affiliatePendingCommissionCents(string $affiliateId): int
    {
        return (int) DB::table('commerce.commission_payouts')
            ->where('affiliate_professional_id', $affiliateId)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('net_payout_cents');
    }

    /**
     * Compute a per-window rate from two windowed counter arrays.
     * Returns null for windows where the denominator is zero (avoids divide-by-zero
     * masquerading as "0% conversion").
     *
     * @param  array<string, int>  $numerator
     * @param  array<string, int>  $denominator
     * @return array<string, ?float>
     */
    private function computeRate(array $numerator, array $denominator, int $multiplier): array
    {
        $result = [];
        foreach (self::WINDOWS as $w) {
            $den = $denominator[$w] ?? 0;
            if ($den === 0) {
                $result[$w] = null;

                continue;
            }
            $result[$w] = round(($numerator[$w] ?? 0) / $den * $multiplier, 2);
        }

        return $result;
    }

    /**
     * Abandoned cart rate per window: (sessions - orders) / sessions * 100. Clamped to [0, 100]
     * because over-counted orders (orders > sessions due to ingest race) shouldn't show -ve.
     *
     * @param  array<string, int>  $sessions
     * @param  array<string, int>  $orders
     * @return array<string, ?float>
     */
    private function computeAbandonedCartRate(array $sessions, array $orders): array
    {
        $result = [];
        foreach (self::WINDOWS as $w) {
            $s = $sessions[$w] ?? 0;
            $o = $orders[$w] ?? 0;
            if ($s === 0) {
                $result[$w] = null;

                continue;
            }
            $rate = max(0.0, min(100.0, ($s - $o) / $s * 100));
            $result[$w] = round($rate, 2);
        }

        return $result;
    }

    /**
     * Top 5 referrers for the affiliate, lifetime. Null/empty referrers grouped under "direct".
     *
     * @return array<int, array{referrer: string, visits: int}>
     */
    private function affiliateTopReferrers(string $affiliateId): array
    {
        return DB::table('analytics.site_visits')
            ->where('professional_id', $affiliateId)
            ->selectRaw("COALESCE(NULLIF(referrer, ''), 'direct') AS referrer, COUNT(*) AS visits")
            ->groupBy('referrer')
            ->orderByDesc('visits')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'referrer' => (string) $r->referrer,
                'visits' => (int) $r->visits,
            ])
            ->all();
    }

    /**
     * Per-section clicks — JOIN link_clicks to site.blocks to surface section label.
     * For v1, we use block_type as the label (e.g. 'gallery', 'shop'). Top 5 by clicks.
     *
     * @return array<int, array{section: string, clicks: int}>
     */
    private function affiliatePerSectionClicks(string $affiliateId): array
    {
        $rows = DB::select('
            SELECT b.block_type AS section, COUNT(*) AS clicks
            FROM analytics.link_clicks lc
            INNER JOIN site.blocks b ON b.id = lc.link_block_id
            WHERE lc.professional_id = ?
            GROUP BY b.block_type
            ORDER BY clicks DESC
            LIMIT 5
        ', [$affiliateId]);

        return array_map(fn ($r) => [
            'section' => (string) ($r->section ?? 'unknown'),
            'clicks' => (int) $r->clicks,
        ], $rows);
    }

    /**
     * Per-section views from analytics.section_views. Top 5 by view count.
     *
     * @return array<int, array{section: string, views: int}>
     */
    private function affiliatePerSectionViews(string $affiliateId): array
    {
        return DB::table('analytics.section_views')
            ->where('professional_id', $affiliateId)
            ->selectRaw('section_key AS section, COUNT(*) AS views')
            ->groupBy('section_key')
            ->orderByDesc('views')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'section' => (string) $r->section,
                'views' => (int) $r->views,
            ])
            ->all();
    }

    /**
     * Per-platform OUTBOUND link clicks — counts clicks ON the affiliate's storefront
     * link buttons grouped by destination platform (Instagram, TikTok, etc.). Inbound
     * attribution (where the visitor came FROM) lives on `top_referrers`, not here.
     *
     * Resolves the platform from each block's icon_key first (explicit marker the site
     * editor set), falling back to the destination URL's host. Anything unrecognised
     * goes to 'Other'.
     *
     * @return array<int, array{platform: string, clicks: int}>
     */
    private function affiliatePerPlatformClicks(string $affiliateId): array
    {
        $rows = DB::select('
            SELECT b.icon_key, b.url, COUNT(*) AS clicks
            FROM analytics.link_clicks lc
            INNER JOIN site.blocks b ON b.id = lc.link_block_id
            WHERE lc.professional_id = ?
              AND b.block_group = ?
            GROUP BY b.icon_key, b.url
        ', [$affiliateId, 'links']);

        $buckets = [];
        foreach ($rows as $r) {
            $platform = $this->normaliseOutboundPlatform(
                isset($r->icon_key) ? (string) $r->icon_key : null,
                isset($r->url) ? (string) $r->url : null,
            );
            $buckets[$platform] = ($buckets[$platform] ?? 0) + (int) $r->clicks;
        }
        arsort($buckets);

        return array_slice(array_map(
            fn ($p, $c) => ['platform' => $p, 'clicks' => $c],
            array_keys($buckets),
            array_values($buckets),
        ), 0, 5);
    }

    /**
     * Resolve a destination platform from a link block's icon_key (preferred) or URL host.
     * Returns 'Other' when neither signal matches a known platform.
     */
    private function normaliseOutboundPlatform(?string $iconKey, ?string $url): string
    {
        $key = strtolower(trim((string) ($iconKey ?? '')));
        $platform = $this->platformFromToken($key);
        if ($platform !== null) {
            return $platform;
        }

        $href = trim((string) ($url ?? ''));
        if ($href === '') {
            return 'Other';
        }
        $host = parse_url($href, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return 'Other';
        }

        return $this->platformFromToken(strtolower($host)) ?? 'Other';
    }

    /**
     * Map a token (icon_key or URL host) to a canonical platform label. Returns null
     * when no match — callers fall back to 'Other'.
     */
    private function platformFromToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        if (str_contains($token, 'instagram')) {
            return 'Instagram';
        }
        if (str_contains($token, 'tiktok')) {
            return 'TikTok';
        }
        if (str_contains($token, 'facebook') || $token === 'fb' || str_contains($token, 'meta')) {
            return 'Facebook';
        }
        if (str_contains($token, 'twitter') || $token === 'x.com' || $token === 'x' || str_contains($token, 't.co')) {
            return 'X (Twitter)';
        }
        if (str_contains($token, 'youtube') || str_contains($token, 'youtu.be') || $token === 'yt') {
            return 'YouTube';
        }
        if (str_contains($token, 'pinterest')) {
            return 'Pinterest';
        }
        if (str_contains($token, 'linkedin')) {
            return 'LinkedIn';
        }
        if (str_contains($token, 'snapchat') || $token === 'snap') {
            return 'Snapchat';
        }
        if (str_contains($token, 'spotify')) {
            return 'Spotify';
        }
        if (str_contains($token, 'whatsapp') || $token === 'wa') {
            return 'WhatsApp';
        }
        if (str_contains($token, 'telegram')) {
            return 'Telegram';
        }
        if (str_contains($token, 'threads')) {
            return 'Threads';
        }

        return null;
    }

    /**
     * Best-selling products for an affiliate — top 5 by gross_cents from commerce.order_items.
     *
     * @return array<int, array{product_id: string, title: ?string, gross_cents: int, orders: int}>
     */
    private function affiliateBestSellingProducts(string $affiliateId): array
    {
        // line_total_cents is the canonical "gross revenue per line" column on commerce.order_items.
        // Raw SQL avoids Laravel's quote-escaping which breaks schema.table.column references on SQLite.
        $excluded = "'".implode("','", Order::EXCLUDED_FROM_AGGREGATES)."'";
        $rows = DB::select("
            SELECT
                oi.shopify_product_id AS product_id,
                oi.title AS title,
                COALESCE(SUM(oi.line_total_cents), 0) AS gross_cents,
                COUNT(DISTINCT o.id) AS orders
            FROM commerce.order_items oi
            INNER JOIN commerce.orders o ON o.id = oi.order_id
            WHERE o.affiliate_professional_id = ?
              AND o.status NOT IN ({$excluded})
            GROUP BY oi.shopify_product_id, oi.title
            ORDER BY gross_cents DESC
            LIMIT 5
        ", [$affiliateId]);

        return array_map(fn ($r) => [
            'product_id' => (string) ($r->product_id ?? ''),
            'title' => $r->title !== null ? (string) $r->title : null,
            'gross_cents' => (int) $r->gross_cents,
            'orders' => (int) $r->orders,
        ], $rows);
    }

    /**
     * Top 5 affiliates for the brand by lifetime gross_cents. Joins to professionals for handle+name.
     *
     * @return array<int, array{affiliate_id: string, name: ?string, handle: ?string, gross_cents: int, orders: int}>
     */
    private function brandTopAffiliates(string $brandId): array
    {
        $rows = DB::select('
            SELECT
                bar.affiliate_professional_id AS affiliate_id,
                p.display_name AS name,
                p.handle AS handle,
                COALESCE(SUM(bar.gross_cents), 0) AS gross_cents,
                COALESCE(SUM(bar.orders_count), 0) AS orders
            FROM commerce.brand_affiliate_rollup bar
            INNER JOIN core.professionals p ON p.id = bar.affiliate_professional_id
            WHERE bar.brand_professional_id = ?
            GROUP BY bar.affiliate_professional_id, p.display_name, p.handle
            ORDER BY gross_cents DESC
            LIMIT 5
        ', [$brandId]);

        return array_map(fn ($r) => [
            'affiliate_id' => (string) $r->affiliate_id,
            'name' => $r->name !== null ? (string) $r->name : null,
            'handle' => $r->handle !== null ? (string) $r->handle : null,
            'gross_cents' => (int) $r->gross_cents,
            'orders' => (int) $r->orders,
        ], $rows);
    }
}
