<?php

namespace App\Http\Controllers\Api\Professional\Booking;

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

// V2: Booking analytics (counts, revenue, customers) from Square/Fresha integrations. Unrelated to V2 commerce.
class BookingAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function myOverview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;
        $timezone = trim((string) ($professional->timezone ?? '')) ?: 'UTC';

        $filters = $this->resolveFilters($request);
        $metricsContext = $this->resolveMetricAggregationContext($filters);

        $ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10);
        $cacheKey = CacheKeyGenerator::bookingAnalytics(
            $professionalId,
            (string) $metricsContext['range_from'],
            (string) $metricsContext['range_to'],
            (string) $metricsContext['group_by']
        );

        return $this->success(Cache::remember($cacheKey, $ttl, function () use ($professionalId, $timezone, $metricsContext): array {
            if ($metricsContext['use_hourly']) {
                $aggregateBase = DB::table('analytics.booking_metrics_hourly as h')
                    ->where('h.professional_id', $professionalId)
                    ->whereBetween('h.hour_start', [$metricsContext['from'], $metricsContext['to']]);

                $totals = (clone $aggregateBase)
                    ->selectRaw('COALESCE(SUM(h.bookings_count), 0) as bookings_count')
                    ->selectRaw('COALESCE(SUM(h.total_spent_cents), 0) as total_spent_cents')
                    ->selectRaw('COALESCE(SUM(h.paid_bookings_count), 0) as paid_bookings_count')
                    ->selectRaw('COALESCE(SUM(h.customers_count), 0) as customers_count')
                    ->first();

                $timeseries = (clone $aggregateBase)
                    ->selectRaw("DATE_TRUNC('hour', h.hour_start) as bucket")
                    ->selectRaw('COALESCE(SUM(h.bookings_count), 0) as bookings_count')
                    ->selectRaw('COALESCE(SUM(h.total_spent_cents), 0) as total_spent_cents')
                    ->groupByRaw("DATE_TRUNC('hour', h.hour_start)")
                    ->orderBy('bucket')
                    ->get();

                $eventsBase = DB::table('analytics.booking_events as e')
                    ->where('e.professional_id', $professionalId)
                    ->whereBetween('e.occurred_at', [$metricsContext['from'], $metricsContext['to']]);

                if ($timeseries->isEmpty()) {
                    $rawBase = clone $eventsBase;

                    $totals = (clone $rawBase)
                        ->selectRaw('COUNT(*) as bookings_count')
                        ->selectRaw('COALESCE(SUM(e.amount_paid_cents), 0) as total_spent_cents')
                        ->selectRaw("COALESCE(SUM(CASE WHEN e.amount_paid_cents > 0 THEN 1 ELSE 0 END), 0) as paid_bookings_count")
                        ->selectRaw("COUNT(DISTINCT NULLIF(lower(trim(e.customer_email)), '')) as customers_count")
                        ->first();

                    $timeseries = (clone $rawBase)
                        ->selectRaw("DATE_TRUNC('hour', e.occurred_at) as bucket")
                        ->selectRaw('COUNT(*) as bookings_count')
                        ->selectRaw('COALESCE(SUM(e.amount_paid_cents), 0) as total_spent_cents')
                        ->groupByRaw("DATE_TRUNC('hour', e.occurred_at)")
                        ->orderBy('bucket')
                        ->get();
                }
            } else {
                $aggregateBase = DB::table('analytics.booking_metrics_daily as d')
                    ->where('d.professional_id', $professionalId)
                    ->whereBetween('d.day', [$metricsContext['from'], $metricsContext['to']]);

                $totals = (clone $aggregateBase)
                    ->selectRaw('COALESCE(SUM(d.bookings_count), 0) as bookings_count')
                    ->selectRaw('COALESCE(SUM(d.total_spent_cents), 0) as total_spent_cents')
                    ->selectRaw('COALESCE(SUM(d.paid_bookings_count), 0) as paid_bookings_count')
                    ->selectRaw('COALESCE(SUM(d.customers_count), 0) as customers_count')
                    ->first();

                $timeseries = (clone $aggregateBase)
                    ->selectRaw('d.day::text as bucket')
                    ->selectRaw('COALESCE(SUM(d.bookings_count), 0) as bookings_count')
                    ->selectRaw('COALESCE(SUM(d.total_spent_cents), 0) as total_spent_cents')
                    ->groupBy('d.day')
                    ->orderBy('d.day')
                    ->get();

                $eventsBase = DB::table('analytics.booking_events as e')
                    ->where('e.professional_id', $professionalId)
                    ->whereRaw('(e.occurred_at AT TIME ZONE ?)::date between ? and ?', [$timezone, $metricsContext['from'], $metricsContext['to']]);

                if ($timeseries->isEmpty()) {
                    $rawBase = clone $eventsBase;

                    $totals = (clone $rawBase)
                        ->selectRaw('COUNT(*) as bookings_count')
                        ->selectRaw('COALESCE(SUM(e.amount_paid_cents), 0) as total_spent_cents')
                        ->selectRaw("COALESCE(SUM(CASE WHEN e.amount_paid_cents > 0 THEN 1 ELSE 0 END), 0) as paid_bookings_count")
                        ->selectRaw("COUNT(DISTINCT NULLIF(lower(trim(e.customer_email)), '')) as customers_count")
                        ->first();

                    $timeseries = (clone $rawBase)
                        ->selectRaw("DATE_TRUNC('day', e.occurred_at AT TIME ZONE ?)::date as bucket", [$timezone])
                        ->selectRaw('COUNT(*) as bookings_count')
                        ->selectRaw('COALESCE(SUM(e.amount_paid_cents), 0) as total_spent_cents')
                        ->groupBy('bucket')
                        ->orderBy('bucket')
                        ->get();
                }
            }

            $events = (clone $eventsBase)
                ->orderByDesc('e.occurred_at')
                ->limit(100)
                ->get([
                    'e.id',
                    'e.square_booking_id',
                    'e.square_payment_id',
                    'e.service_name',
                    'e.customer_name',
                    'e.customer_email',
                    'e.payment_method',
                    'e.status',
                    'e.currency_code',
                    'e.amount_paid_cents',
                    'e.occurred_at',
                    'e.appointment_start_at',
                ]);

            $currencyCode = strtoupper(trim((string) (
                $events->first()->currency_code
                ?? 'AUD'
            )));
            if ($currencyCode === '') {
                $currencyCode = 'AUD';
            }

            return [
                'range' => [
                    'from' => $metricsContext['range_from'],
                    'to' => $metricsContext['range_to'],
                ],
                'group_by' => $metricsContext['group_by'],
                'granularity' => $metricsContext['granularity'],
                'bucket_timezone' => $metricsContext['bucket_timezone'],
                'totals' => [
                    'bookings_count' => (int) ($totals->bookings_count ?? 0),
                    'total_spent_cents' => (int) ($totals->total_spent_cents ?? 0),
                    'paid_bookings_count' => (int) ($totals->paid_bookings_count ?? 0),
                    'customers_count' => (int) ($totals->customers_count ?? 0),
                    'currency_code' => $currencyCode,
                ],
                'timeseries' => $timeseries->map(static fn ($row): array => [
                    'bucket' => (string) $row->bucket,
                    'bookings_count' => (int) ($row->bookings_count ?? 0),
                    'total_spent_cents' => (int) ($row->total_spent_cents ?? 0),
                ])->values()->all(),
                'events' => $events->map(static fn ($row): array => [
                    'event_id' => (string) $row->id,
                    'booking_id' => $row->square_booking_id !== null ? (string) $row->square_booking_id : null,
                    'payment_id' => $row->square_payment_id !== null ? (string) $row->square_payment_id : null,
                    'service_name' => $row->service_name !== null ? (string) $row->service_name : null,
                    'customer_name' => $row->customer_name !== null ? (string) $row->customer_name : null,
                    'customer_email' => $row->customer_email !== null ? (string) $row->customer_email : null,
                    'payment_method' => $row->payment_method !== null ? (string) $row->payment_method : null,
                    'status' => $row->status !== null ? (string) $row->status : null,
                    'currency_code' => $row->currency_code !== null ? (string) $row->currency_code : $currencyCode,
                    'amount_paid_cents' => (int) ($row->amount_paid_cents ?? 0),
                    'occurred_at' => $row->occurred_at !== null ? (string) $row->occurred_at : null,
                    'appointment_start_at' => $row->appointment_start_at !== null ? (string) $row->appointment_start_at : null,
                ])->values()->all(),
            ];
        }));
    }

    /**
     * @return array{from: string, to: string, group_by: string}
     */
    private function resolveFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'group_by' => ['sometimes', 'in:hour,day'],
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
                    'to' => ['to must be after from.'],
                ]);
            }

            return [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'group_by' => (string) ($validated['group_by'] ?? 'day'),
                'explicit_range' => true,
            ];
        }

        $days = max(1, min(365, (int) ($validated['days'] ?? 30)));
        $to = now()->toDateString();
        $from = now()->subDays($days - 1)->toDateString();

        return [
            'from' => $from,
            'to' => $to,
            'group_by' => (string) ($validated['group_by'] ?? ($days === 1 ? 'hour' : 'day')),
            'explicit_range' => false,
        ];
    }

    /**
     * @param  array{from: string, to: string, group_by: string}  $filters
     * @return array{
     *   from: mixed,
     *   to: mixed,
     *   range_from: string,
     *   range_to: string,
     *   group_by: string,
     *   granularity: string,
     *   bucket_timezone: string|null,
     *   use_hourly: bool
     * }
     */
    private function resolveMetricAggregationContext(array $filters): array
    {
        $requestedGroupBy = (string) ($filters['group_by'] ?? 'day');
        $from = Carbon::createFromFormat('Y-m-d', (string) $filters['from'])->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', (string) $filters['to'])->endOfDay();
        $cutoff = now()->utc()->subHours(24);
        $forceHourly = $requestedGroupBy === 'hour';

        $useHourly = $forceHourly || (
            $from->copy()->utc()->gte($cutoff)
            && $to->copy()->utc()->lte(now()->utc()->addMinute())
        );

        $hourlyFrom = $from->copy()->utc();
        $hourlyTo = $to->copy()->min(now())->utc();
        if ($forceHourly && !($filters['explicit_range'] ?? false)) {
            $hourlyTo = now()->utc();
            $hourlyFrom = $hourlyTo->copy()->subHours(24)->startOfHour();
        }

        return [
            'from' => $useHourly ? $hourlyFrom : (string) $filters['from'],
            'to' => $useHourly ? $hourlyTo : (string) $filters['to'],
            'range_from' => $useHourly ? $hourlyFrom->toDateString() : (string) $filters['from'],
            'range_to' => $useHourly ? $hourlyTo->toDateString() : (string) $filters['to'],
            'group_by' => $useHourly ? 'hour' : 'day',
            'granularity' => $useHourly ? 'hour' : 'day',
            'bucket_timezone' => $useHourly ? 'UTC' : null,
            'use_hourly' => $useHourly,
        ];
    }
}
