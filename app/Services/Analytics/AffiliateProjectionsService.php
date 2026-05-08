<?php

namespace App\Services\Analytics;

use App\Models\Core\Professional\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the affiliate projections payload (run-rate, momentum, year-end forecast,
 * YTD, best month, engagement). Pure read path — no writes, no side effects.
 *
 * All math runs from `commerce.brand_affiliate_rollup`, the trigger-maintained
 * per-(day, brand, affiliate, currency) aggregation table. We never query
 * `commerce.orders` directly: the rollup is the public read interface.
 *
 * @return array{
 *   as_of: string,
 *   data_history_days: int,
 *   status: 'ok'|'insufficient_data',
 *   window: array{days:int, from:string, to:string}|null,
 *   engagement: array{earning_days_count:int, active_brand_count:int},
 *   by_currency: array<int, array<string, mixed>>
 * }
 */
class AffiliateProjectionsService
{
    public function build(Professional $professional, ?int $windowDaysOverride = null): array
    {
        $tz = $professional->timezone ?: 'UTC';
        $now = CarbonImmutable::now($tz);

        $dataHistoryDays = $this->resolveDataHistoryDays($professional->id, $now);
        $windowDays = $windowDaysOverride !== null
            ? $this->validateOverride($windowDaysOverride, $dataHistoryDays)
            : $this->selectWindowDays($dataHistoryDays);

        if ($windowDays === null) {
            return [
                'as_of' => $now->toIso8601String(),
                'data_history_days' => $dataHistoryDays,
                'status' => 'insufficient_data',
                'window' => null,
                'engagement' => ['earning_days_count' => 0, 'active_brand_count' => 0],
                'by_currency' => [],
            ];
        }

        // The window is the most recent N complete days *up to and including yesterday* in pro's TZ.
        // Today is in-flight, so excluding it avoids dragging the rate down with a half-day.
        $windowTo = $now->subDay()->startOfDay();
        $windowFrom = $windowTo->subDays($windowDays - 1);

        $perCurrency = $this->fetchPerCurrencyAggregates(
            $professional->id,
            $windowFrom->toDateString(),
            $windowTo->toDateString(),
            $windowDays
        );

        // Prior window: the same-length window immediately preceding the current one.
        $priorWindowTo = $windowFrom->subDay();
        $priorWindowFrom = $priorWindowTo->subDays($windowDays - 1);
        $priorByCurrency = $this->fetchPriorWindowAggregates(
            $professional->id,
            $priorWindowFrom->toDateString(),
            $priorWindowTo->toDateString()
        )->keyBy('currency_code');

        $yearStart = $now->startOfYear()->toDateString();
        $ytdByCurrency = $this->fetchYtdAggregates($professional->id, $yearStart)->keyBy('currency_code');
        $bestMonthByCurrency = $this->fetchBestMonthPerCurrency($professional->id, $yearStart)->keyBy('currency_code');
        // Carbon 3 returns signed diffs by default; call diffInDays on the EARLIER date
        // with the LATER date as the argument so we get a positive day count.
        $daysRemainingInYear = (int) $now->startOfDay()->diffInDays($now->endOfYear()->startOfDay());

        // Engagement is currency-agnostic: max across rows.
        $engagement = [
            'earning_days_count' => (int) ($perCurrency->max('earning_days') ?? 0),
            'active_brand_count' => (int) ($perCurrency->max('brand_count') ?? 0),
        ];

        $byCurrency = $perCurrency
            ->sortByDesc('window_net_cents')
            ->values()
            ->map(fn ($row) => $this->buildCurrencyEntry(
                $row,
                $windowDays,
                $now,
                $priorByCurrency,
                $dataHistoryDays,
                $ytdByCurrency,
                $bestMonthByCurrency,
                $daysRemainingInYear,
            ))
            ->all();

        return [
            'as_of' => $now->toIso8601String(),
            'data_history_days' => $dataHistoryDays,
            'status' => 'ok',
            'window' => [
                'days' => $windowDays,
                'from' => $windowFrom->toDateString(),
                'to' => $windowTo->toDateString(),
            ],
            'engagement' => $engagement,
            'by_currency' => $byCurrency,
        ];
    }

    /**
     * One SQL round-trip aggregating window stats per currency. Returns a Collection
     * of stdClass with keys: currency_code, window_net_cents, window_orders, earning_days,
     * brand_count, daily_values_json (JSON-encoded array of per-day net cents, length=window_days).
     */
    private function fetchPerCurrencyAggregates(
        string $affiliateId,
        string $from,
        string $to,
        int $windowDays,
    ): \Illuminate\Support\Collection {
        return DB::table('commerce.brand_affiliate_rollup')
            ->where('affiliate_professional_id', $affiliateId)
            ->whereBetween('day', [$from, $to])
            ->groupBy('currency_code')
            ->selectRaw('
                currency_code,
                COALESCE(SUM(commission_cents - reversed_commission_cents), 0) AS window_net_cents,
                COALESCE(SUM(orders_count), 0) AS window_orders,
                COUNT(DISTINCT day) FILTER (WHERE (commission_cents - reversed_commission_cents) > 0) AS earning_days,
                COUNT(DISTINCT brand_professional_id) FILTER (WHERE (commission_cents - reversed_commission_cents) > 0) AS brand_count,
                COALESCE(
                    jsonb_agg(commission_cents - reversed_commission_cents ORDER BY day),
                    \'[]\'::jsonb
                )::text AS daily_values_json
            ')
            ->get();
    }

    /**
     * Aggregate net commission per currency for the prior window period.
     * Returns a Collection of stdClass with keys: currency_code, prior_net_cents.
     */
    private function fetchPriorWindowAggregates(
        string $affiliateId,
        string $from,
        string $to,
    ): \Illuminate\Support\Collection {
        return DB::table('commerce.brand_affiliate_rollup')
            ->where('affiliate_professional_id', $affiliateId)
            ->whereBetween('day', [$from, $to])
            ->groupBy('currency_code')
            ->selectRaw('
                currency_code,
                COALESCE(SUM(commission_cents - reversed_commission_cents), 0) AS prior_net_cents
            ')
            ->get();
    }

    /**
     * Sum of net commission and order count since Jan 1 of the current year, per currency.
     */
    private function fetchYtdAggregates(
        string $affiliateId,
        string $yearStart,
    ): \Illuminate\Support\Collection {
        return DB::table('commerce.brand_affiliate_rollup')
            ->where('affiliate_professional_id', $affiliateId)
            ->where('day', '>=', $yearStart)
            ->groupBy('currency_code')
            ->selectRaw('
                currency_code,
                COALESCE(SUM(commission_cents - reversed_commission_cents), 0) AS ytd_net_cents,
                COALESCE(SUM(orders_count), 0) AS ytd_orders
            ')
            ->get();
    }

    /**
     * Per currency, returns the month-of-year (YYYY-MM) with the highest net commission YTD.
     * Uses Postgres DISTINCT ON + window function — safe because the rollup table only ever
     * lives on Postgres.
     */
    private function fetchBestMonthPerCurrency(
        string $affiliateId,
        string $yearStart,
    ): \Illuminate\Support\Collection {
        return DB::table('commerce.brand_affiliate_rollup')
            ->fromRaw("(
                SELECT
                    currency_code,
                    date_trunc('month', day) AS month_start,
                    day,
                    SUM(commission_cents - reversed_commission_cents) OVER (
                        PARTITION BY currency_code, date_trunc('month', day)
                    ) AS month_net
                FROM commerce.brand_affiliate_rollup
                WHERE affiliate_professional_id = ?
                  AND day >= ?
            ) AS monthly", [$affiliateId, $yearStart])
            ->selectRaw("
                DISTINCT ON (currency_code)
                currency_code,
                to_char(month_start, 'YYYY-MM') AS best_month,
                month_net AS best_month_net_cents
            ")
            ->orderByRaw('currency_code, month_net DESC')
            ->get();
    }

    private function buildCurrencyEntry(
        object $row,
        int $windowDays,
        CarbonImmutable $now,
        \Illuminate\Support\Collection $priorByCurrency,
        int $dataHistoryDays,
        \Illuminate\Support\Collection $ytdByCurrency,
        \Illuminate\Support\Collection $bestMonthByCurrency,
        int $daysRemainingInYear,
    ): array {
        $netCents = (int) $row->window_net_cents;
        $orders = (int) $row->window_orders;

        $runRateCentsPerDay = (int) round($netCents / $windowDays);
        $ordersPerDay = round($orders / $windowDays, 2);

        $annualCommission = (int) round($runRateCentsPerDay * 365);
        $annualOrders = (int) round($ordersPerDay * 365);

        // Momentum: compare current run-rate to the same-length window immediately prior.
        // pct_change = null when prior run-rate is zero (no earnings baseline) to avoid div-by-zero.
        $priorRow = $priorByCurrency->get($row->currency_code);
        $priorNet = $priorRow ? (int) $priorRow->prior_net_cents : 0;
        $priorRunRate = (int) round($priorNet / $windowDays);
        $pctChange = $priorRunRate > 0
            ? round(($runRateCentsPerDay - $priorRunRate) / $priorRunRate, 4)
            : null;

        $ytdRow = $ytdByCurrency->get($row->currency_code);
        $ytdNet = $ytdRow ? (int) $ytdRow->ytd_net_cents : 0;
        $ytdOrders = $ytdRow ? (int) $ytdRow->ytd_orders : 0;

        $bestMonthRow = $bestMonthByCurrency->get($row->currency_code);
        $bestMonth = $bestMonthRow ? (string) $bestMonthRow->best_month : null;
        $bestMonthNet = $bestMonthRow ? (int) $bestMonthRow->best_month_net_cents : 0;

        // Year-end forecast: carry forward what's already earned YTD, then add run-rate * remaining days.
        $yearEndCommission = (int) ($ytdNet + ($runRateCentsPerDay * $daysRemainingInYear));

        return [
            'currency_code' => (string) $row->currency_code,
            'run_rate' => [
                'commission_cents_per_day' => $runRateCentsPerDay,
                'orders_per_day' => $ordersPerDay,
            ],
            'projections' => [
                'annual_commission_cents' => $annualCommission,
                'year_end_commission_cents' => $yearEndCommission,
                'annual_orders' => $annualOrders,
                'confidence' => $this->confidenceBand($dataHistoryDays, $row->daily_values_json),
            ],
            'momentum' => [
                'pct_change_vs_prior_window' => $pctChange,
                'prior_run_rate_cents_per_day' => $priorRunRate,
            ],
            'ytd' => [
                'commission_cents' => $ytdNet,
                'orders_count' => $ytdOrders,
                'best_month' => $bestMonth,
                'best_month_commission_cents' => $bestMonthNet,
            ],
        ];
    }

    /**
     * High: ≥90d history AND CV < 0.5.
     * Medium: ≥30d history AND CV < 1.0 (or ≥30d with CV unmeasurable).
     * Low: anything else qualifying for the smallest tier.
     *
     * CV is computed from the JSON array of daily net cents. If the JSON is malformed
     * or the array is empty, we fall back to 'low' rather than throwing — this is a
     * degraded-but-honest signal, not a hard failure.
     */
    private function confidenceBand(int $dataHistoryDays, ?string $dailyValuesJson): string
    {
        $high = config('partna.commerce_analytics.projections_confidence_high');
        $medium = config('partna.commerce_analytics.projections_confidence_medium');

        $cv = $this->coefficientOfVariation($dailyValuesJson);

        if ($dataHistoryDays >= ($high['min_history_days'] ?? 90)
            && $cv !== null
            && $cv < ($high['max_cv'] ?? 0.5)
        ) {
            return 'high';
        }
        if ($dataHistoryDays >= ($medium['min_history_days'] ?? 30)
            && ($cv === null || $cv < ($medium['max_cv'] ?? 1.0))
        ) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Population coefficient of variation (stddev / mean).
     * Returns null when mean is 0 (CV undefined) or values are missing.
     */
    private function coefficientOfVariation(?string $dailyValuesJson): ?float
    {
        if ($dailyValuesJson === null || $dailyValuesJson === '') {
            return null;
        }
        $values = json_decode($dailyValuesJson, true);
        if (! is_array($values) || count($values) === 0) {
            return null;
        }
        $n = count($values);
        $mean = array_sum($values) / $n;
        if ($mean <= 0) {
            return null;
        }
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += (((float) $v) - $mean) ** 2;
        }
        $variance /= $n;

        return sqrt($variance) / $mean;
    }

    /**
     * Days between today (in pro's timezone) and the affiliate's earliest rollup day.
     * Returns 0 if no rollup rows exist for this affiliate.
     */
    private function resolveDataHistoryDays(string $affiliateId, CarbonImmutable $now): int
    {
        $earliest = DB::table('commerce.brand_affiliate_rollup')
            ->where('affiliate_professional_id', $affiliateId)
            ->orderBy('day', 'asc')
            ->value('day');

        if ($earliest === null) {
            return 0;
        }

        return (int) CarbonImmutable::parse($earliest)->diffInDays($now->startOfDay());
    }

    /**
     * Pick the largest tier the affiliate has >= days of history for.
     * Returns null if below the smallest tier.
     */
    private function selectWindowDays(int $dataHistoryDays): ?int
    {
        $tiers = config('partna.commerce_analytics.projections_window_tiers', [90, 60, 30, 14]);
        rsort($tiers);
        foreach ($tiers as $tier) {
            // History must strictly exceed the tier so the window is fully covered
            // (e.g. exactly 60 days of history is not enough for a 60-day window since
            // the oldest day may be incomplete; require at least tier+1 days).
            if ($dataHistoryDays > $tier) {
                return (int) $tier;
            }
        }

        return null;
    }

    /**
     * Validates an explicit window_days override against available history.
     * Returns null (→ insufficient_data) if the affiliate doesn't have enough data
     * for the requested window. Never silently expands or shrinks — the caller
     * asked for N days, they get N days or insufficient_data.
     *
     * Uses the same strict-`>` semantics as selectWindowDays: history must EXCEED
     * the requested tier so the oldest day in the window is fully complete.
     */
    private function validateOverride(int $requested, int $dataHistoryDays): ?int
    {
        $tiers = config('partna.commerce_analytics.projections_window_tiers', [90, 60, 30, 14]);
        if (! in_array($requested, $tiers, true)) {
            return null; // form request validation should catch this; defensive fallback
        }

        return $dataHistoryDays > $requested ? $requested : null;
    }
}
