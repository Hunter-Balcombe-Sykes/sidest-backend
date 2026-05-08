<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Commerce\Order;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BrandCommerceAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(private CacheLockService $cacheLock) {}

    /**
     * Brand's commerce performance overview.
     * Live queries against commerce.orders + commerce.brand_affiliate_rollup
     * + analytics.site_visits.
     *
     * @return JsonResponse{ data: { range, granularity, totals, timeseries, affiliates, commission_summary } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;

        $filters = $this->resolveFilters($request);
        // v3: cache key bumped from v2 because derivation changed to live commerce queries
        $cacheKey = CacheKeyGenerator::brandCommerceAnalytics($professionalId, $filters['from'], $filters['to']);

        return $this->success($this->cacheLock->rememberLocked($cacheKey, 60, function () use ($professionalId, $filters): array {
            $granularity = $filters['use_hourly'] ? 'hour' : 'day';

            // ── Totals from commerce.orders ──────────────────────────────────
            $totalsRow = $this->queryOrderTotals($professionalId, $filters, filterBrand: true);

            $currencyCode = $totalsRow->currency_code ?? 'AUD';
            $totals = [
                'orders_count' => (int) ($totalsRow->orders_count ?? 0),
                'gross_cents' => (int) ($totalsRow->gross_cents ?? 0),
                'refunded_cents' => (int) ($totalsRow->refunded_cents ?? 0),
                'net_cents' => (int) ($totalsRow->net_cents ?? 0),
                'currency_code' => strtoupper($currencyCode),
            ];

            // ── Timeseries from commerce.orders ──────────────────────────────
            $timeseries = $this->queryOrderTimeseries($professionalId, $filters, $granularity, filterBrand: true);

            // ── Brand page-views from analytics.site_visits ──────────────────
            // The brand professional_id IS the professional_id for the brand's own pages.
            [$totalPageviews, $totalUniqueVisitors] = $this->querySiteVisitTotals($professionalId, $filters);

            $totals['page_views'] = $totalPageviews;
            $totals['unique_visitors'] = $totalUniqueVisitors;

            // ── Cart events from analytics.cart_events (across all affiliates) ─
            [$totalCartAdds, $totalCheckouts] = $this->queryCartEventCounts($professionalId, $filters);
            $totals['cart_adds'] = $totalCartAdds;
            $totals['checkouts'] = $totalCheckouts;

            // ── Per-affiliate breakdown from commerce.brand_affiliate_rollup ─
            $affiliates = $this->buildAffiliateBreakdown($professionalId, $filters, $currencyCode);

            // ── Commission summary ────────────────────────────────────────────
            $commissionSummary = $this->buildCommissionSummary($professionalId, $filters, $currencyCode);

            return [
                'range' => ['from' => $filters['from'], 'to' => $filters['to']],
                'granularity' => $granularity,
                'totals' => $totals,
                'timeseries' => $timeseries,
                'affiliates' => $affiliates,
                'commission_summary' => $commissionSummary,
            ];
        }));
    }

    /**
     * Aggregate order totals for a brand or affiliate in the requested window.
     * Returns a stdClass with orders_count, gross_cents, refunded_cents, net_cents, currency_code.
     */
    private function queryOrderTotals(string $professionalId, array $filters, bool $filterBrand): object
    {
        $column = $filterBrand ? 'brand_professional_id' : 'affiliate_professional_id';

        $row = DB::table('commerce.orders')
            ->where($column, $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            ->selectRaw('
                COUNT(*) AS orders_count,
                COALESCE(SUM(gross_cents), 0) AS gross_cents,
                COALESCE(SUM(refund_cents), 0) AS refunded_cents,
                COALESCE(SUM(net_cents), 0) AS net_cents
            ')
            ->first();

        // Determine the dominant currency from the period's orders (most orders wins).
        $currencyRow = DB::table('commerce.orders')
            ->where($column, $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            ->selectRaw('currency_code, COUNT(*) as cnt')
            ->groupBy('currency_code')
            ->orderByDesc('cnt')
            ->first();

        if ($row) {
            $row->currency_code = $currencyRow->currency_code ?? 'AUD';
        } else {
            $row = (object) ['orders_count' => 0, 'gross_cents' => 0, 'refunded_cents' => 0, 'net_cents' => 0, 'currency_code' => 'AUD'];
        }

        return $row;
    }

    /**
     * Order timeseries bucketed by hour or day.
     * Returns array of ['bucket' => string, 'orders_count' => int, 'gross_cents' => int, 'net_cents' => int]
     */
    private function queryOrderTimeseries(string $professionalId, array $filters, string $granularity, bool $filterBrand, bool $includeCommission = false): array
    {
        $column = $filterBrand ? 'brand_professional_id' : 'affiliate_professional_id';
        $conn = DB::connection('pgsql');
        $driver = $conn->getDriverName();

        // DATE_TRUNC is Postgres-only — fall back to strftime for SQLite test environment
        if ($driver === 'sqlite') {
            $fmtMap = ['hour' => '%Y-%m-%d %H:00:00', 'day' => '%Y-%m-%d'];
            $fmt = $fmtMap[$granularity] ?? '%Y-%m-%d';
            $bucketExpr = "strftime('{$fmt}', occurred_at)";
        } else {
            $bucketExpr = "DATE_TRUNC('{$granularity}', occurred_at)";
        }

        $commissionSelect = $includeCommission
            ? ', COALESCE(SUM(commission_cents), 0) AS commission_accrued_cents'
            : '';

        $rows = DB::table('commerce.orders')
            ->where($column, $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            ->selectRaw("
                {$bucketExpr} AS bucket,
                COUNT(*) AS orders_count,
                COALESCE(SUM(gross_cents), 0) AS gross_cents,
                COALESCE(SUM(net_cents), 0) AS net_cents
                {$commissionSelect}
            ")
            ->groupByRaw($bucketExpr)
            ->orderByRaw($bucketExpr)
            ->get();

        return $rows->map(function ($row) use ($granularity, $includeCommission) {
            $bucket = $granularity === 'hour'
                ? Carbon::parse($row->bucket)->toIso8601String()
                : (string) $row->bucket;

            $entry = [
                'bucket' => $bucket,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
            ];

            if ($includeCommission) {
                $entry['commission_accrued_cents'] = (int) $row->commission_accrued_cents;
            }

            return $entry;
        })->values()->all();
    }

    /**
     * Total page views + unique visitors for the brand's own site in the window.
     * Returns [$pageViews, $uniqueVisitors].
     */
    private function querySiteVisitTotals(string $professionalId, array $filters): array
    {
        $row = DB::table('analytics.site_visits')
            ->where('professional_id', $professionalId)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            ->selectRaw('COUNT(*) AS page_views, COUNT(DISTINCT visitor_id) AS unique_visitors')
            ->first();

        return [(int) ($row->page_views ?? 0), (int) ($row->unique_visitors ?? 0)];
    }

    /**
     * Cart add and checkout start counts across all of the brand's affiliates.
     * Returns [$cartAdds, $checkouts].
     */
    private function queryCartEventCounts(string $brandProfessionalId, array $filters): array
    {
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('affiliate_professional_id')
            ->toArray();

        if (empty($affiliateIds)) {
            return [0, 0];
        }

        $endOfDay = Carbon::parse($filters['to'])->endOfDay();

        $cartAdds = (int) DB::table('analytics.cart_events')
            ->whereIn('professional_id', $affiliateIds)
            ->where('event_type', 'cart_add')
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', $endOfDay)
            ->count();

        $checkouts = (int) DB::table('analytics.cart_events')
            ->whereIn('professional_id', $affiliateIds)
            ->where('event_type', 'checkout_start')
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', $endOfDay)
            ->count();

        return [$cartAdds, $checkouts];
    }

    /**
     * Per-affiliate breakdown using the trigger-maintained brand_affiliate_rollup.
     * Joins core.professionals for display labels. Adds customers_count from orders
     * and per-affiliate site visits (page_views / unique_visitors / conversion_rate).
     *
     * @return array<int, array>
     */
    private function buildAffiliateBreakdown(string $professionalId, array $filters, string $currencyCode): array
    {
        // Aggregate from the rollup — fast, trigger-maintained
        $rollupRows = DB::table('commerce.brand_affiliate_rollup')
            ->where('brand_professional_id', $professionalId)
            ->where('day', '>=', $filters['from'])
            ->where('day', '<=', $filters['to'])
            ->where('currency_code', $currencyCode)
            ->selectRaw('
                affiliate_professional_id,
                SUM(orders_count) AS orders_count,
                SUM(gross_cents) AS gross_cents,
                SUM(gross_cents - refund_cents) AS net_cents,
                SUM(commission_cents - reversed_commission_cents) AS commission_net_cents
            ')
            ->groupBy('affiliate_professional_id')
            ->orderByRaw('SUM(commission_cents - reversed_commission_cents) DESC')
            ->limit(100)
            ->get();

        if ($rollupRows->isEmpty()) {
            return [];
        }

        $affiliateIds = $rollupRows->pluck('affiliate_professional_id')->all();

        // Identity labels for affiliates
        $identityRows = DB::table('core.professionals')
            ->whereIn('id', $affiliateIds)
            ->whereNull('deleted_at')
            ->select('id', 'display_name', 'first_name', 'last_name', 'handle')
            ->get()
            ->keyBy('id');

        // customers_count per affiliate from orders (not in rollup)
        $customerCounts = DB::table('commerce.orders')
            ->where('brand_professional_id', $professionalId)
            ->whereIn('affiliate_professional_id', $affiliateIds)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            ->selectRaw('affiliate_professional_id, COUNT(DISTINCT customer_id) AS customers_count')
            ->groupBy('affiliate_professional_id')
            ->get()
            ->keyBy('affiliate_professional_id');

        // Per-affiliate site visits (from each affiliate's own pages)
        $pageviewsByAffiliate = DB::table('analytics.site_visits')
            ->whereIn('professional_id', $affiliateIds)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            ->selectRaw('professional_id, COUNT(*) AS page_views, COUNT(DISTINCT visitor_id) AS unique_visitors')
            ->groupBy('professional_id')
            ->get()
            ->keyBy('professional_id');

        return $rollupRows->map(function ($row) use ($identityRows, $customerCounts, $pageviewsByAffiliate, $currencyCode) {
            $affiliateId = (string) $row->affiliate_professional_id;
            $identity = $identityRows->get($affiliateId);

            $fullName = trim(implode(' ', array_filter([$identity?->first_name, $identity?->last_name])));
            $displayName = $fullName !== ''
                ? $fullName
                : ($identity?->display_name ?? $identity?->handle ?? 'Affiliate');

            $orders = (int) $row->orders_count;
            $views = $pageviewsByAffiliate->get($affiliateId);
            $uniqueVisitors = (int) ($views?->unique_visitors ?? 0);
            $conversionRate = $uniqueVisitors > 0
                ? round(($orders / $uniqueVisitors) * 100, 2)
                : 0.0;

            return [
                'affiliate_professional_id' => $affiliateId,
                'affiliate_display_name' => $displayName,
                'affiliate_handle' => $identity?->handle,
                'orders_count' => $orders,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
                'commission_net_cents' => (int) $row->commission_net_cents,
                'customers_count' => (int) ($customerCounts->get($affiliateId)?->customers_count ?? 0),
                'page_views' => (int) ($views?->page_views ?? 0),
                'unique_visitors' => $uniqueVisitors,
                'conversion_rate_percent' => $conversionRate,
                'currency_code' => strtoupper($currencyCode),
            ];
        })->values()->all();
    }

    /**
     * Commission summary derived from live tables:
     *   approved_cents  = SUM(commission_cents) on non-excluded orders
     *   paid_cents      = SUM(commission_cents) WHERE payout_id IS NOT NULL
     *   reversed_cents  = SUM(reversed_commission_cents) from rollup
     *   pending_cents   = clamp(approved - paid - reversed, 0, ∞)
     */
    private function buildCommissionSummary(string $professionalId, array $filters, string $currencyCode): array
    {
        $endOfDay = Carbon::parse($filters['to'])->endOfDay();

        // approved = all non-excluded commissions in window
        $approvedRow = DB::table('commerce.orders')
            ->where('brand_professional_id', $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', $endOfDay)
            ->selectRaw('COALESCE(SUM(commission_cents), 0) AS approved_cents')
            ->first();

        // paid = subset with a payout assigned
        $paidRow = DB::table('commerce.orders')
            ->where('brand_professional_id', $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->whereNotNull('payout_id')
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', $endOfDay)
            ->selectRaw('COALESCE(SUM(commission_cents), 0) AS paid_cents')
            ->first();

        // reversed = sum from rollup
        $reversedRow = DB::table('commerce.brand_affiliate_rollup')
            ->where('brand_professional_id', $professionalId)
            ->where('day', '>=', $filters['from'])
            ->where('day', '<=', $filters['to'])
            ->selectRaw('COALESCE(SUM(reversed_commission_cents), 0) AS reversed_cents')
            ->first();

        $approvedCents = (int) ($approvedRow->approved_cents ?? 0);
        $paidCents = (int) ($paidRow->paid_cents ?? 0);
        $reversedCents = (int) ($reversedRow->reversed_cents ?? 0);
        // pending = approved minus what's already paid or reversed; clamp to >= 0
        $pendingCents = max(0, $approvedCents - $paidCents - $reversedCents);

        return [
            'pending_cents' => $pendingCents,
            'approved_cents' => $approvedCents,
            'paid_cents' => $paidCents,
            'reversed_cents' => $reversedCents,
            'currency_code' => strtoupper($currencyCode),
        ];
    }

    private function resolveFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $hasFrom = isset($validated['from']);
        $hasTo = isset($validated['to']);

        if ($hasFrom xor $hasTo) {
            throw ValidationException::withMessages([
                'from' => ['from and to must be provided together.'],
                'to' => ['from and to must be provided together.'],
            ]);
        }

        if ($hasFrom && $hasTo) {
            $from = Carbon::createFromFormat('Y-m-d', (string) $validated['from']);
            $to = Carbon::createFromFormat('Y-m-d', (string) $validated['to']);

            if ($from->gt($to)) {
                throw ValidationException::withMessages([
                    'from' => ['from must be before to.'],
                ]);
            }

            return $this->buildFilterContext($from->toDateString(), $to->toDateString());
        }

        $days = max(1, min(365, (int) ($validated['days'] ?? 30)));

        return $this->buildFilterContext(
            now()->subDays($days - 1)->toDateString(),
            now()->toDateString()
        );
    }

    private function buildFilterContext(string $from, string $to): array
    {
        $cutoff = now()->utc()->subHours(24)->startOfHour();
        $fromUtc = Carbon::parse($from)->utc()->startOfDay();
        $toUtc = Carbon::parse($to)->utc()->endOfDay();

        // Use hourly granularity when the entire range falls within the last 24h.
        $useHourly = $fromUtc->gte($cutoff) && $toUtc->lte(now()->utc()->addMinute());

        return [
            'from' => $from,
            'to' => $to,
            'use_hourly' => $useHourly,
        ];
    }
}
