<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AffiliateCommerceAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(private CacheLockService $cacheLock) {}

    /**
     * Affiliate's own commerce performance summary.
     * Uses professional_metrics_hourly for the last 24h, professional_metrics_daily for older ranges.
     * When multiple currencies exist, the one with the most orders is used for totals.
     *
     * @return JsonResponse{ data: { range: {from: string, to: string}, granularity: string, totals: array, timeseries: array } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;

        $filters = $this->resolveFilters($request);
        $cacheKey = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, $filters['from'], $filters['to']);

        return $this->success($this->cacheLock->rememberLocked($cacheKey, now()->addMinutes(5), function () use ($professionalId, $filters): array {
            $base = $filters['use_hourly']
                ? $this->buildHourlyResponse($professionalId, $filters)
                : $this->buildDailyResponse($professionalId, $filters);

            // Layer per-brand breakdown, payout summary, and grace summary
            // on top of the orders/commission timeseries. The brand
            // breakdown respects the filter window; payout + grace
            // summaries describe the affiliate's *current* state regardless
            // of which window they're looking at.
            return array_merge($base, [
                'brands' => $this->buildBrandBreakdown($professionalId, $filters, $base['totals']['currency_code']),
                'payout_summary' => $this->buildPayoutSummary($professionalId),
                'grace_summary' => $this->buildGraceSummary($professionalId),
            ]);
        }));
    }

    private function buildDailyResponse(string $professionalId, array $filters): array
    {
        $rows = DB::table('analytics.professional_metrics_daily')
            ->where('affiliate_professional_id', $professionalId)
            ->whereBetween('day', [$filters['from'], $filters['to']])
            ->get();

        $currencyCode = $rows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
        $primary = $rows->filter(fn ($r) => $r->currency_code === $currencyCode);

        return [
            'range' => ['from' => $filters['from'], 'to' => $filters['to']],
            'granularity' => 'day',
            'totals' => $this->sumTotals($primary, $currencyCode),
            'timeseries' => $primary->sortBy('day')->map(fn ($row) => [
                'bucket' => (string) $row->day,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
                'commission_accrued_cents' => (int) $row->commission_accrued_cents,
            ])->values()->all(),
        ];
    }

    private function buildHourlyResponse(string $professionalId, array $filters): array
    {
        $rows = DB::table('analytics.professional_metrics_hourly')
            ->where('affiliate_professional_id', $professionalId)
            ->where('hour_start', '>=', $filters['hourly_from'])
            ->where('hour_start', '<', $filters['hourly_to'])
            ->get();

        $currencyCode = $rows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
        $primary = $rows->filter(fn ($r) => $r->currency_code === $currencyCode);

        return [
            'range' => ['from' => $filters['from'], 'to' => $filters['to']],
            'granularity' => 'hour',
            'totals' => $this->sumTotals($primary, $currencyCode),
            'timeseries' => $primary->sortBy('hour_start')->map(fn ($row) => [
                'bucket' => Carbon::parse($row->hour_start)->toIso8601String(),
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
                'commission_accrued_cents' => (int) $row->commission_accrued_cents,
            ])->values()->all(),
        ];
    }

    /**
     * Per-brand breakdown — each row of brand_affiliate_daily filtered to
     * THIS affiliate, summed across the requested window, joined to the
     * brand's identity (display_name, handle) for UI labelling.
     *
     * Returns rows for every brand the affiliate had activity with in the
     * window, sorted by gross revenue desc so the highest-performing
     * brand reads first. Currency is filtered to the primary currency
     * for the affiliate so multi-currency rows don't double-count.
     */
    private function buildBrandBreakdown(string $professionalId, array $filters, string $currencyCode): array
    {
        $rows = DB::table('analytics.brand_affiliate_daily as bad')
            ->leftJoin('core.professionals as brand', 'brand.id', '=', 'bad.brand_professional_id')
            ->where('bad.affiliate_professional_id', $professionalId)
            ->where('bad.currency_code', $currencyCode)
            ->whereBetween('bad.day', [$filters['from'], $filters['to']])
            ->select(
                'bad.brand_professional_id',
                'brand.display_name as brand_display_name',
                'brand.handle as brand_handle',
                DB::raw('CAST(SUM(bad.orders_count) AS INTEGER) as orders_count'),
                DB::raw('CAST(SUM(bad.gross_cents) AS INTEGER) as gross_cents'),
                DB::raw('CAST(SUM(bad.net_cents) AS INTEGER) as net_cents'),
                DB::raw('CAST(SUM(bad.commission_accrued_cents) AS INTEGER) as commission_accrued_cents'),
                DB::raw('CAST(SUM(bad.commission_net_cents) AS INTEGER) as commission_net_cents'),
                DB::raw('CAST(SUM(bad.customers_count) AS INTEGER) as customers_count'),
            )
            ->groupBy('bad.brand_professional_id', 'brand.display_name', 'brand.handle')
            ->orderByDesc(DB::raw('SUM(bad.gross_cents)'))
            ->get();

        return $rows->map(fn ($row) => [
            'brand_professional_id' => (string) $row->brand_professional_id,
            'brand_display_name' => (string) ($row->brand_display_name ?? ''),
            'brand_handle' => $row->brand_handle ? (string) $row->brand_handle : null,
            'orders_count' => (int) $row->orders_count,
            'gross_cents' => (int) $row->gross_cents,
            'net_cents' => (int) $row->net_cents,
            'commission_accrued_cents' => (int) $row->commission_accrued_cents,
            'commission_net_cents' => (int) $row->commission_net_cents,
            'customers_count' => (int) $row->customers_count,
            'currency_code' => strtoupper($currencyCode),
        ])->values()->all();
    }

    /**
     * Snapshot of the affiliate's payout state — independent of the
     * filter window. Surfaces:
     *   - estimated next payout (sum of approved-not-yet-paid commissions
     *     past their hold-days threshold)
     *   - the earliest eligible_after timestamp across pending payouts
     *   - last completed payout (for "last paid" KPI on the dashboard)
     */
    private function buildPayoutSummary(string $professionalId): array
    {
        $emptyShape = [
            'next_payout_estimate_cents' => 0,
            'next_payout_eligible_at' => null,
            'last_payout_at' => null,
            'last_payout_amount_cents' => 0,
            'currency_code' => 'AUD',
        ];

        try {
            // Pending / eligible amounts — payouts that exist but haven't
            // completed yet. Includes 'pending', 'pending_funds',
            // 'collecting', 'transferring' (anything mid-flight). The
            // pending_funds status was added with the grace migration —
            // safe to include even when the enum doesn't carry it because
            // SQL just treats unknown values as no-match.
            $pendingAgg = DB::table('commerce.commission_payouts')
                ->where('affiliate_professional_id', $professionalId)
                ->whereIn('status', ['pending', 'pending_funds', 'collecting', 'transferring'])
                ->selectRaw('
                    CAST(COALESCE(SUM(net_payout_cents), 0) AS INTEGER) as net_pending_cents,
                    MIN(eligible_after) as next_eligible_at,
                    MAX(currency_code) as currency_code
                ')
                ->first();

            // Last completed payout — for the "last paid X.XX on date" line.
            $lastCompleted = DB::table('commerce.commission_payouts')
                ->where('affiliate_professional_id', $professionalId)
                ->where('status', 'completed')
                ->orderByDesc('processed_at')
                ->select('processed_at', 'net_payout_cents', 'currency_code')
                ->first();

            return [
                'next_payout_estimate_cents' => (int) ($pendingAgg->net_pending_cents ?? 0),
                'next_payout_eligible_at' => $pendingAgg && $pendingAgg->next_eligible_at
                    ? Carbon::parse($pendingAgg->next_eligible_at)->toIso8601String()
                    : null,
                'last_payout_at' => $lastCompleted && $lastCompleted->processed_at
                    ? Carbon::parse($lastCompleted->processed_at)->toIso8601String()
                    : null,
                'last_payout_amount_cents' => (int) ($lastCompleted->net_payout_cents ?? 0),
                'currency_code' => strtoupper($lastCompleted->currency_code ?? $pendingAgg->currency_code ?? 'AUD'),
            ];
        } catch (QueryException $e) {
            // Guard: SQLSTATE 42703 (undefined column) from schema drift — the
            // grace migration has shipped, but this catch remains as a safety
            // net for that specific case. Re-throw everything else so real bugs
            // (connection failures, cast errors) surface in Nightwatch.
            if (($e->errorInfo[0] ?? null) !== '42703') {
                throw $e;
            }
            \Illuminate\Support\Facades\Log::warning('buildPayoutSummary failed; returning empty', [
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);

            return $emptyShape;
        }
    }

    /**
     * Per-payout grace state — surfaces the most urgent void deadline so
     * the dashboard can show "X days left or you lose $Y" with the
     * earliest expiring payout's numbers, not an average. Empty when
     * the affiliate has Stripe connected and active (no payouts at risk).
     */
    private function buildGraceSummary(string $professionalId): array
    {
        // Defensive empty shape — used when Stripe is connected, when the
        // affiliate has nothing at risk, or when the void_at migration
        // hasn't shipped yet (the column-missing exception below falls
        // through to this exact shape so the dashboard still renders).
        $emptyShape = [
            'status' => 'none',
            'earliest_expiring_payout_at' => null,
            'earliest_at_risk_amount_cents' => 0,
            'total_at_risk_cents' => 0,
            'days_remaining' => null,
            'currency_code' => 'AUD',
        ];

        try {
            $professional = DB::table('core.professionals')
                ->where('id', $professionalId)
                ->whereNull('deleted_at')
                ->select('stripe_connect_status')
                ->first();

            $isActive = $professional && $professional->stripe_connect_status === 'active';

            // When Stripe is active, no payouts can void for grace reasons —
            // return a clean shape so the UI can short-circuit.
            if ($isActive) {
                return [...$emptyShape, 'status' => 'connected'];
            }

            // Aggregate unvoided pending payouts that haven't been paid out.
            // void_at is per-payout (created_at + 60d) so the earliest deadline
            // dictates the most urgent banner.
            $atRisk = DB::table('commerce.commission_payouts')
                ->where('affiliate_professional_id', $professionalId)
                ->whereIn('status', ['pending', 'pending_funds'])
                ->where('void_at', '>', now())
                ->selectRaw('
                    COUNT(*) as payout_count,
                    CAST(COALESCE(SUM(net_payout_cents), 0) AS INTEGER) as total_cents,
                    MIN(void_at) as earliest_at,
                    MAX(currency_code) as currency_code
                ')
                ->first();

            $earliestPayoutCents = 0;
            if ($atRisk && $atRisk->earliest_at) {
                $earliest = DB::table('commerce.commission_payouts')
                    ->where('affiliate_professional_id', $professionalId)
                    ->where('void_at', '=', $atRisk->earliest_at)
                    ->whereIn('status', ['pending', 'pending_funds'])
                    ->orderBy('net_payout_cents', 'desc') // deterministic tie-break when batch creates share a void_at timestamp
                    ->select('net_payout_cents')
                    ->first();
                $earliestPayoutCents = (int) ($earliest->net_payout_cents ?? 0);
            }

            // Status tone driven by days remaining. Mirrors the banner logic:
            //   critical = ≤3d  warning = ≤14d  active = >14d  none = no payouts at risk
            $earliestAt = $atRisk && $atRisk->earliest_at ? Carbon::parse($atRisk->earliest_at) : null;
            $daysRemaining = $earliestAt ? (int) max(0, now()->diffInDays($earliestAt, false)) : null;

            $status = match (true) {
                ($atRisk->payout_count ?? 0) === 0 => 'none',
                $daysRemaining !== null && $daysRemaining <= 3 => 'critical',
                $daysRemaining !== null && $daysRemaining <= 14 => 'warning',
                default => 'active',
            };

            return [
                'status' => $status,
                'earliest_expiring_payout_at' => $earliestAt?->toIso8601String(),
                'earliest_at_risk_amount_cents' => $earliestPayoutCents,
                'total_at_risk_cents' => (int) ($atRisk->total_cents ?? 0),
                'days_remaining' => $daysRemaining,
                'currency_code' => strtoupper($atRisk->currency_code ?? 'AUD'),
            ];
        } catch (QueryException $e) {
            // Guard: SQLSTATE 42703 (undefined column) — originally for void_at
            // before the grace migration shipped. Re-throw everything else so
            // real bugs surface in Nightwatch rather than silently returning
            // an empty banner to the dashboard.
            if (($e->errorInfo[0] ?? null) !== '42703') {
                throw $e;
            }
            \Illuminate\Support\Facades\Log::warning('buildGraceSummary failed; returning empty', [
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);

            return $emptyShape;
        }
    }

    /** @param \Illuminate\Support\Collection $rows */
    private function sumTotals($rows, string $currencyCode): array
    {
        return [
            'orders_count' => (int) $rows->sum('orders_count'),
            'gross_cents' => (int) $rows->sum('gross_cents'),
            'refunded_cents' => (int) $rows->sum('refunded_cents'),
            'net_cents' => (int) $rows->sum('net_cents'),
            'commission_accrued_cents' => (int) $rows->sum('commission_accrued_cents'),
            'commission_reversed_cents' => (int) $rows->sum('commission_reversed_cents'),
            'commission_paid_cents' => (int) $rows->sum('commission_paid_cents'),
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

        // Use hourly table when the entire range falls within the last 24h.
        $useHourly = $fromUtc->gte($cutoff) && $toUtc->lte(now()->utc()->addMinute());

        return [
            'from' => $from,
            'to' => $to,
            'use_hourly' => $useHourly,
            'hourly_from' => $cutoff,
            'hourly_to' => now()->utc()->addMinute(),
        ];
    }
}
