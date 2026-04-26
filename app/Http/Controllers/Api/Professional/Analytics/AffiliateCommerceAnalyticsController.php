<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
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
            if ($filters['use_hourly']) {
                return $this->buildHourlyResponse($professionalId, $filters);
            }

            return $this->buildDailyResponse($professionalId, $filters);
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
