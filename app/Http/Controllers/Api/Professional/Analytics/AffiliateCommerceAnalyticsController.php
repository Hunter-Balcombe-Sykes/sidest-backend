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

class AffiliateCommerceAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    /**
     * Affiliate's own commerce performance summary.
     * Reads from analytics.professional_metrics_daily (pre-aggregated per day per currency).
     * When multiple currencies exist, the one with the most orders is used for totals.
     *
     * @return JsonResponse{ data: { range: {from: string, to: string}, totals: array, timeseries: array } }
     */
    public function overview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;

        $filters = $this->resolveFilters($request);
        $from = $filters['from'];
        $to = $filters['to'];

        $cacheKey = CacheKeyGenerator::affiliateCommerceAnalytics($professionalId, $from, $to);

        return $this->success(Cache::remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $from, $to): array {
            $rows = DB::table('analytics.professional_metrics_daily')
                ->where('affiliate_professional_id', $professionalId)
                ->whereBetween('day', [$from, $to])
                ->get();

            // Pick dominant currency (most orders); fall back to AUD if no data.
            $currencyCode = $rows->sortByDesc('orders_count')->first()?->currency_code ?? 'AUD';
            $primary = $rows->filter(fn ($r) => $r->currency_code === $currencyCode);

            $totals = [
                'orders_count' => (int) $primary->sum('orders_count'),
                'gross_cents' => (int) $primary->sum('gross_cents'),
                'refunded_cents' => (int) $primary->sum('refunded_cents'),
                'net_cents' => (int) $primary->sum('net_cents'),
                'commission_accrued_cents' => (int) $primary->sum('commission_accrued_cents'),
                'commission_reversed_cents' => (int) $primary->sum('commission_reversed_cents'),
                'commission_paid_cents' => (int) $primary->sum('commission_paid_cents'),
                'currency_code' => strtoupper($currencyCode),
            ];

            $timeseries = $primary->sortBy('day')->map(fn ($row) => [
                'bucket' => (string) $row->day,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'net_cents' => (int) $row->net_cents,
                'commission_accrued_cents' => (int) $row->commission_accrued_cents,
            ])->values()->all();

            return [
                'range' => ['from' => $from, 'to' => $to],
                'totals' => $totals,
                'timeseries' => $timeseries,
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
