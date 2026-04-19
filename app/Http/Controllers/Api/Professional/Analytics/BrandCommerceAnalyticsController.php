<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BrandCommerceAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    /**
     * Brand's commerce performance overview.
     * Brand totals use brand_metrics_hourly for last 24h, brand_metrics_daily for older ranges.
     * Per-affiliate breakdown and commission summary always use their daily tables (no hourly equivalent).
     *
     * @return JsonResponse{ data: { range, granularity, totals, timeseries, affiliates, commission_summary } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;

        $filters = $this->resolveFilters($request);
        $cacheKey = CacheKeyGenerator::brandCommerceAnalytics($professionalId, $filters['from'], $filters['to']);

        return $this->success(Cache::remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $filters): array {
            [$totals, $timeseries, $currencyCode] = $filters['use_hourly']
                ? $this->buildHourlyTotals($professionalId, $filters)
                : $this->buildDailyTotals($professionalId, $filters);

            // Per-affiliate breakdown — daily only (no hourly equivalent table)
            $affiliateRows = DB::table('analytics.brand_affiliate_daily')
                ->where('brand_professional_id', $professionalId)
                ->whereBetween('day', [$filters['from'], $filters['to']])
                ->get()
                ->groupBy('affiliate_professional_id');

            $affiliates = $affiliateRows->map(function ($rows, $affiliateId) {
                $affiliateCurrency = $rows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
                $primary = $rows->filter(fn ($r) => $r->currency_code === $affiliateCurrency);

                return [
                    'affiliate_professional_id' => $affiliateId,
                    'orders_count' => (int) $primary->sum('orders_count'),
                    'gross_cents' => (int) $primary->sum('gross_cents'),
                    'net_cents' => (int) $primary->sum('net_cents'),
                    'commission_net_cents' => (int) $primary->sum('commission_net_cents'),
                    'customers_count' => (int) $primary->sum('customers_count'),
                    'currency_code' => strtoupper($affiliateCurrency),
                ];
            })->values()->all();

            // Commission summary — daily only
            $commissionRows = DB::table('analytics.brand_commission_daily')
                ->where('brand_professional_id', $professionalId)
                ->whereBetween('day', [$filters['from'], $filters['to']])
                ->get()
                ->groupBy('payout_status');

            $commissionCurrencyCode = $commissionRows->flatten(1)->first()?->currency_code ?? $currencyCode;

            $commissionSummary = [
                'pending_cents' => (int) ($commissionRows->get('pending')?->sum('net_outstanding_cents') ?? 0),
                'approved_cents' => (int) ($commissionRows->get('approved')?->sum('net_outstanding_cents') ?? 0),
                'paid_cents' => (int) ($commissionRows->get('paid')?->sum('payout_cents') ?? 0),
                'reversed_cents' => (int) ($commissionRows->get('reversed')?->sum('reversal_cents') ?? 0),
                'currency_code' => strtoupper($commissionCurrencyCode),
            ];

            return [
                'range' => ['from' => $filters['from'], 'to' => $filters['to']],
                'granularity' => $filters['use_hourly'] ? 'hour' : 'day',
                'totals' => $totals,
                'timeseries' => $timeseries,
                'affiliates' => $affiliates,
                'commission_summary' => $commissionSummary,
            ];
        }));
    }

    /** @return array{0: array, 1: array, 2: string} [totals, timeseries, currencyCode] */
    private function buildDailyTotals(string $professionalId, array $filters): array
    {
        $rows = DB::table('analytics.brand_metrics_daily')
            ->where('brand_professional_id', $professionalId)
            ->whereBetween('day', [$filters['from'], $filters['to']])
            ->get();

        $currencyCode = $rows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
        $primary = $rows->filter(fn ($r) => $r->currency_code === $currencyCode);

        $totals = [
            'orders_count' => (int) $primary->sum('orders_count'),
            'gross_cents' => (int) $primary->sum('gross_cents'),
            'refunded_cents' => (int) $primary->sum('refunded_cents'),
            'net_cents' => (int) $primary->sum('net_cents'),
            'currency_code' => strtoupper($currencyCode),
        ];

        $timeseries = $primary->sortBy('day')->map(fn ($row) => [
            'bucket' => (string) $row->day,
            'orders_count' => (int) $row->orders_count,
            'gross_cents' => (int) $row->gross_cents,
            'net_cents' => (int) $row->net_cents,
        ])->values()->all();

        return [$totals, $timeseries, $currencyCode];
    }

    /** @return array{0: array, 1: array, 2: string} [totals, timeseries, currencyCode] */
    private function buildHourlyTotals(string $professionalId, array $filters): array
    {
        $rows = DB::table('analytics.brand_metrics_hourly')
            ->where('brand_professional_id', $professionalId)
            ->where('hour_start', '>=', $filters['hourly_from'])
            ->where('hour_start', '<', $filters['hourly_to'])
            ->get();

        $currencyCode = $rows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
        $primary = $rows->filter(fn ($r) => $r->currency_code === $currencyCode);

        $totals = [
            'orders_count' => (int) $primary->sum('orders_count'),
            'gross_cents' => (int) $primary->sum('gross_cents'),
            'refunded_cents' => (int) $primary->sum('refunded_cents'),
            'net_cents' => (int) $primary->sum('net_cents'),
            'currency_code' => strtoupper($currencyCode),
        ];

        $timeseries = $primary->sortBy('hour_start')->map(fn ($row) => [
            'bucket' => Carbon::parse($row->hour_start)->toIso8601String(),
            'orders_count' => (int) $row->orders_count,
            'gross_cents' => (int) $row->gross_cents,
            'net_cents' => (int) $row->net_cents,
        ])->values()->all();

        return [$totals, $timeseries, $currencyCode];
    }

    private function resolveFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
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
