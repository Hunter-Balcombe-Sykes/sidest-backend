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
     * Returns brand-level totals (brand_metrics_daily), per-affiliate breakdown
     * (brand_affiliate_daily), and commission status summary (brand_commission_daily).
     * All three tables are queried and merged into a single cached response.
     *
     * @return JsonResponse{ data: { range, totals, timeseries, affiliates, commission_summary } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;

        $filters = $this->resolveFilters($request);
        $from = $filters['from'];
        $to = $filters['to'];

        $cacheKey = CacheKeyGenerator::brandCommerceAnalytics($professionalId, $from, $to);

        return $this->success(Cache::remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $from, $to): array {
            // Brand-level totals and daily timeseries
            $brandRows = DB::table('analytics.brand_metrics_daily')
                ->where('brand_professional_id', $professionalId)
                ->whereBetween('day', [$from, $to])
                ->get();

            $currencyCode = $brandRows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
            $primaryBrand = $brandRows->filter(fn ($r) => $r->currency_code === $currencyCode);

            $totals = [
                'orders_count' => (int) $primaryBrand->sum('orders_count'),
                'gross_cents' => (int) $primaryBrand->sum('gross_cents'),
                'refunded_cents' => (int) $primaryBrand->sum('refunded_cents'),
                'net_cents' => (int) $primaryBrand->sum('net_cents'),
                'currency_code' => strtoupper($currencyCode),
            ];

            $timeseries = $primaryBrand->sortBy('day')->map(fn ($row) => [
                'bucket' => (string) $row->day,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
            ])->values()->all();

            // Per-affiliate breakdown — sum each affiliate's rows across the date range
            $affiliateRows = DB::table('analytics.brand_affiliate_daily')
                ->where('brand_professional_id', $professionalId)
                ->whereBetween('day', [$from, $to])
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

            // Commission status totals across all affiliates for the date range
            $commissionRows = DB::table('analytics.brand_commission_daily')
                ->where('brand_professional_id', $professionalId)
                ->whereBetween('day', [$from, $to])
                ->get()
                ->groupBy('payout_status');

            $commissionSummary = [
                'pending_cents' => (int) ($commissionRows->get('pending')?->sum('net_outstanding_cents') ?? 0),
                'approved_cents' => (int) ($commissionRows->get('approved')?->sum('net_outstanding_cents') ?? 0),
                'paid_cents' => (int) ($commissionRows->get('paid')?->sum('payout_cents') ?? 0),
                'reversed_cents' => (int) ($commissionRows->get('reversed')?->sum('reversal_cents') ?? 0),
                'currency_code' => strtoupper($currencyCode),
            ];

            return [
                'range' => ['from' => $from, 'to' => $to],
                'totals' => $totals,
                'timeseries' => $timeseries,
                'affiliates' => $affiliates,
                'commission_summary' => $commissionSummary,
            ];
        }));
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

            return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }

        $days = max(1, min(365, (int) ($validated['days'] ?? 30)));

        return [
            'from' => now()->subDays($days - 1)->toDateString(),
            'to' => now()->toDateString(),
        ];
    }
}
