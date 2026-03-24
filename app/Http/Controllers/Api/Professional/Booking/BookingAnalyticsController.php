<?php

namespace App\Http\Controllers\Api\Professional\Booking;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $base = DB::table('analytics.booking_events as e')
            ->where('e.professional_id', $professionalId)
            ->whereRaw('(e.occurred_at AT TIME ZONE ?)::date between ? and ?', [$timezone, $from, $to]);

        $totals = (clone $base)
            ->selectRaw('COUNT(*) as bookings_count')
            ->selectRaw('COALESCE(SUM(e.amount_paid_cents), 0) as total_spent_cents')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.amount_paid_cents > 0 THEN 1 ELSE 0 END), 0) as paid_bookings_count")
            ->selectRaw("COUNT(DISTINCT NULLIF(lower(trim(e.customer_email)), '')) as customers_count")
            ->first();

        $timeseries = (clone $base)
            ->selectRaw("DATE_TRUNC('day', e.occurred_at AT TIME ZONE ?)::date as bucket", [$timezone])
            ->selectRaw('COUNT(*) as bookings_count')
            ->selectRaw('COALESCE(SUM(e.amount_paid_cents), 0) as total_spent_cents')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

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
