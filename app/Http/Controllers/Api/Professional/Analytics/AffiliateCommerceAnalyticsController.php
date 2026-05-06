<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Commerce\Order;
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
     * Live queries against commerce.orders + commerce.brand_affiliate_rollup.
     * payout_summary and grace_summary read commerce.commission_payouts directly.
     *
     * @return JsonResponse{ data: { range, granularity, totals, timeseries, brands, payout_summary, grace_summary } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;

        $filters = $this->resolveFilters($request);
        $windowKey = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, $filters['from'], $filters['to']);
        $payoutStateKey = CacheKeyGenerator::affiliatePayoutState($professionalId);

        // Timeseries + brands depend on the selected date window.
        $windowed = $this->cacheLock->rememberLocked($windowKey, 60, function () use ($professionalId, $filters): array {
            $granularity = $filters['use_hourly'] ? 'hour' : 'day';

            // ── Totals from commerce.orders ──────────────────────────────────
            $totalsRow = $this->queryOrderTotals($professionalId, $filters);
            $currencyCode = $totalsRow->currency_code ?? 'AUD';

            // commission_reversed_cents comes from the rollup (trigger-maintained)
            $reversedRow = DB::table('commerce.brand_affiliate_rollup')
                ->where('affiliate_professional_id', $professionalId)
                ->where('day', '>=', $filters['from'])
                ->where('day', '<=', $filters['to'])
                ->selectRaw('COALESCE(SUM(reversed_commission_cents), 0) AS reversed_cents')
                ->first();

            // commission_paid_cents: orders with an assigned payout in window
            $paidRow = DB::table('commerce.orders')
                ->where('affiliate_professional_id', $professionalId)
                ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
                ->whereNotNull('payout_id')
                ->where('occurred_at', '>=', $filters['from'])
                ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
                ->selectRaw('COALESCE(SUM(commission_cents), 0) AS paid_cents')
                ->first();

            $totals = [
                'orders_count' => (int) ($totalsRow->orders_count ?? 0),
                'gross_cents' => (int) ($totalsRow->gross_cents ?? 0),
                'refunded_cents' => (int) ($totalsRow->refunded_cents ?? 0),
                'net_cents' => (int) ($totalsRow->net_cents ?? 0),
                'commission_accrued_cents' => (int) ($totalsRow->commission_cents ?? 0),
                'commission_reversed_cents' => (int) ($reversedRow->reversed_cents ?? 0),
                'commission_paid_cents' => (int) ($paidRow->paid_cents ?? 0),
                'currency_code' => strtoupper($currencyCode),
            ];

            // ── Timeseries from commerce.orders ──────────────────────────────
            $timeseries = $this->queryOrderTimeseries($professionalId, $filters, $granularity);

            // ── Per-brand breakdown from rollup ───────────────────────────────
            $brands = $this->buildBrandBreakdown($professionalId, $filters, $currencyCode);

            return [
                'range' => ['from' => $filters['from'], 'to' => $filters['to']],
                'granularity' => $granularity,
                'totals' => $totals,
                'timeseries' => $timeseries,
                'brands' => $brands,
            ];
        });

        // Payout + grace state describe the affiliate's current position and
        // are cached per-professional so switching date windows reuses one entry.
        $payoutState = $this->cacheLock->rememberLocked($payoutStateKey, 60, function () use ($professionalId): array {
            return [
                'payout_summary' => $this->buildPayoutSummary($professionalId),
                'grace_summary' => $this->buildGraceSummary($professionalId),
            ];
        });

        return $this->success(array_merge($windowed, $payoutState));
    }

    /**
     * Aggregate order totals for the affiliate in the requested window.
     * Returns a stdClass with orders_count, gross_cents, refunded_cents, net_cents, commission_cents, currency_code.
     */
    private function queryOrderTotals(string $professionalId, array $filters): object
    {
        $endOfDay = Carbon::parse($filters['to'])->endOfDay();

        $row = DB::table('commerce.orders')
            ->where('affiliate_professional_id', $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', $endOfDay)
            ->selectRaw('
                COUNT(*) AS orders_count,
                COALESCE(SUM(gross_cents), 0) AS gross_cents,
                COALESCE(SUM(refund_cents), 0) AS refunded_cents,
                COALESCE(SUM(net_cents), 0) AS net_cents,
                COALESCE(SUM(commission_cents), 0) AS commission_cents
            ')
            ->first();

        // Dominant currency by order count
        $currencyRow = DB::table('commerce.orders')
            ->where('affiliate_professional_id', $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', $endOfDay)
            ->selectRaw('currency_code, COUNT(*) as cnt')
            ->groupBy('currency_code')
            ->orderByDesc('cnt')
            ->first();

        if ($row) {
            $row->currency_code = $currencyRow->currency_code ?? 'AUD';
        } else {
            $row = (object) [
                'orders_count' => 0, 'gross_cents' => 0, 'refunded_cents' => 0,
                'net_cents' => 0, 'commission_cents' => 0, 'currency_code' => 'AUD',
            ];
        }

        return $row;
    }

    /**
     * Order timeseries bucketed by hour or day, including commission_accrued_cents.
     * Returns array of ['bucket', 'orders_count', 'gross_cents', 'net_cents', 'commission_accrued_cents']
     */
    private function queryOrderTimeseries(string $professionalId, array $filters, string $granularity): array
    {
        $conn = DB::connection('pgsql');
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            $fmtMap = ['hour' => '%Y-%m-%d %H:00:00', 'day' => '%Y-%m-%d'];
            $fmt = $fmtMap[$granularity] ?? '%Y-%m-%d';
            $bucketExpr = "strftime('{$fmt}', occurred_at)";
        } else {
            $bucketExpr = "DATE_TRUNC('{$granularity}', occurred_at)";
        }

        $rows = DB::table('commerce.orders')
            ->where('affiliate_professional_id', $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            ->selectRaw("
                {$bucketExpr} AS bucket,
                COUNT(*) AS orders_count,
                COALESCE(SUM(gross_cents), 0) AS gross_cents,
                COALESCE(SUM(net_cents), 0) AS net_cents,
                COALESCE(SUM(commission_cents), 0) AS commission_accrued_cents
            ")
            ->groupByRaw($bucketExpr)
            ->orderByRaw($bucketExpr)
            ->get();

        return $rows->map(function ($row) use ($granularity) {
            $bucket = $granularity === 'hour'
                ? Carbon::parse($row->bucket)->toIso8601String()
                : (string) $row->bucket;

            return [
                'bucket' => $bucket,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
                'commission_accrued_cents' => (int) $row->commission_accrued_cents,
            ];
        })->values()->all();
    }

    /**
     * Per-brand breakdown for this affiliate using the rollup table.
     * Joins core.professionals for brand display labels.
     * customers_count aggregated from orders (not in rollup).
     *
     * @return array<int, array>
     */
    private function buildBrandBreakdown(string $professionalId, array $filters, string $currencyCode): array
    {
        $rollupRows = DB::table('commerce.brand_affiliate_rollup as r')
            ->leftJoin('core.professionals as brand', 'brand.id', '=', 'r.brand_professional_id')
            ->where('r.affiliate_professional_id', $professionalId)
            ->where('r.currency_code', $currencyCode)
            ->where('r.day', '>=', $filters['from'])
            ->where('r.day', '<=', $filters['to'])
            ->selectRaw('
                r.brand_professional_id,
                brand.display_name AS brand_display_name,
                brand.handle AS brand_handle,
                SUM(r.orders_count) AS orders_count,
                SUM(r.gross_cents) AS gross_cents,
                SUM(r.gross_cents - r.refund_cents) AS net_cents,
                SUM(r.commission_cents) AS commission_accrued_cents,
                SUM(r.commission_cents - r.reversed_commission_cents) AS commission_net_cents
            ')
            ->groupBy('r.brand_professional_id', 'brand.display_name', 'brand.handle')
            ->orderByRaw('SUM(r.gross_cents) DESC')
            ->get();

        if ($rollupRows->isEmpty()) {
            return [];
        }

        $brandIds = $rollupRows->pluck('brand_professional_id')->all();

        // customers_count per brand — not stored in rollup
        $customerCounts = DB::table('commerce.orders')
            ->where('affiliate_professional_id', $professionalId)
            ->whereIn('brand_professional_id', $brandIds)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('occurred_at', '>=', $filters['from'])
            ->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            ->selectRaw('brand_professional_id, COUNT(DISTINCT customer_id) AS customers_count')
            ->groupBy('brand_professional_id')
            ->get()
            ->keyBy('brand_professional_id');

        return $rollupRows->map(function ($row) use ($customerCounts, $currencyCode) {
            return [
                'brand_professional_id' => (string) $row->brand_professional_id,
                'brand_display_name' => (string) ($row->brand_display_name ?? ''),
                'brand_handle' => $row->brand_handle ? (string) $row->brand_handle : null,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
                'commission_accrued_cents' => (int) $row->commission_accrued_cents,
                'commission_net_cents' => (int) $row->commission_net_cents,
                'customers_count' => (int) ($customerCounts->get($row->brand_professional_id)?->customers_count ?? 0),
                'currency_code' => strtoupper($currencyCode),
            ];
        })->values()->all();
    }

    /**
     * Snapshot of the affiliate's payout state — independent of the
     * filter window. Surfaces:
     *   - estimated next payout (sum of approved-not-yet-paid commissions)
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
            $pendingAgg = DB::table('commerce.commission_payouts')
                ->where('affiliate_professional_id', $professionalId)
                ->whereIn('status', ['pending', 'pending_funds', 'collecting', 'transferring'])
                ->selectRaw('
                    CAST(COALESCE(SUM(net_payout_cents), 0) AS INTEGER) as net_pending_cents,
                    MIN(eligible_after) as next_eligible_at,
                    MAX(currency_code) as currency_code
                ')
                ->first();

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
            // Guard: SQLSTATE 42703 (undefined column) from schema drift.
            // Re-throw everything else so real bugs surface in Nightwatch.
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
     * the dashboard can show "X days left or you lose $Y". Empty when Stripe is connected.
     */
    private function buildGraceSummary(string $professionalId): array
    {
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

            if ($isActive) {
                return [...$emptyShape, 'status' => 'connected'];
            }

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
                    ->orderBy('net_payout_cents', 'desc')
                    ->select('net_payout_cents')
                    ->first();
                $earliestPayoutCents = (int) ($earliest->net_payout_cents ?? 0);
            }

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
