<?php

namespace App\Http\Controllers\Api\Professional\Booking;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function myOverview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $professionalId = (string) $professional->id;
        $timezone = trim((string) ($professional->timezone ?? '')) ?: 'UTC';

        $days = (int) $request->query('days', 30);
        $days = max(1, min($days, 365));

        $to = now()->setTimezone($timezone)->toDateString();
        $from = now()->setTimezone($timezone)->subDays($days - 1)->toDateString();

        $dailyBase = DB::table('analytics.booking_metrics_daily as d')
            ->where('d.professional_id', $professionalId)
            ->whereBetween('d.day', [$from, $to]);

        $rangeEndUtc = now()->utc();
        $rangeStartUtc = $rangeEndUtc->copy()->subHours(24)->startOfHour();
        $useHourly = $days === 1;

        $totals = (clone $dailyBase)
            ->selectRaw('COALESCE(SUM(d.bookings_count), 0) as bookings_count')
            ->selectRaw('COALESCE(SUM(d.total_spent_cents), 0) as total_spent_cents')
            ->selectRaw('COALESCE(SUM(d.paid_bookings_count), 0) as paid_bookings_count')
            ->selectRaw('COALESCE(SUM(d.customers_count), 0) as customers_count')
            ->first();

        $timeseries = (clone $dailyBase)
            ->selectRaw('d.day::text as bucket')
            ->selectRaw('COALESCE(SUM(d.bookings_count), 0) as bookings_count')
            ->selectRaw('COALESCE(SUM(d.total_spent_cents), 0) as total_spent_cents')
            ->groupBy('d.day')
            ->orderBy('d.day')
            ->get();

        if ($useHourly) {
            $hourlyBase = DB::table('analytics.booking_metrics_hourly as h')
                ->where('h.professional_id', $professionalId)
                ->whereBetween('h.hour_start', [$rangeStartUtc, $rangeEndUtc]);

            $totals = (clone $hourlyBase)
                ->selectRaw('COALESCE(SUM(h.bookings_count), 0) as bookings_count')
                ->selectRaw('COALESCE(SUM(h.total_spent_cents), 0) as total_spent_cents')
                ->selectRaw('COALESCE(SUM(h.paid_bookings_count), 0) as paid_bookings_count')
                ->selectRaw('COALESCE(SUM(h.customers_count), 0) as customers_count')
                ->first();

            $timeseries = (clone $hourlyBase)
                ->selectRaw("DATE_TRUNC('hour', h.hour_start) as bucket")
                ->selectRaw('COALESCE(SUM(h.bookings_count), 0) as bookings_count')
                ->selectRaw('COALESCE(SUM(h.total_spent_cents), 0) as total_spent_cents')
                ->groupByRaw("DATE_TRUNC('hour', h.hour_start)")
                ->orderBy('bucket')
                ->get();
        } elseif ($timeseries->isEmpty()) {
            $rawBase = DB::table('analytics.booking_events as e')
                ->where('e.professional_id', $professionalId)
                ->whereRaw('(e.occurred_at AT TIME ZONE ?)::date between ? and ?', [$timezone, $from, $to]);

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

        $events = (clone $base)
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
            ]);

        $currencyCode = strtoupper(trim((string) (
            $events->first()->currency_code
            ?? 'AUD'
        )));
        if ($currencyCode === '') {
            $currencyCode = 'AUD';
        }

        return $this->success([
            'range' => [
                'from' => $from,
                'to' => $to,
            ],
            'granularity' => $useHourly ? 'hour' : 'day',
            'bucket_timezone' => $useHourly ? 'UTC' : $timezone,
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
            ])->values()->all(),
        ]);
    }
}
