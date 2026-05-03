<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Concerns\ResolvesTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// V2: Aggregates booking metrics (counts, revenue, customers) from Square/Fresha. Not V2 commerce — booking analytics only.
class BookingAnalyticsAggregateService
{
    use ResolvesTimezone;

    public function rebuildProfessionalHour(string $professionalId, Carbon|string $hourStart): void
    {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $hour = Carbon::parse($hourStart)->utc()->startOfHour();
        $hourEnd = $hour->copy()->addHour();
        $timezone = $this->professionalTimezone($professionalId);
        $now = now();

        DB::transaction(function () use ($professionalId, $hour, $hourEnd, $timezone, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["analytics-rebuild:{$professionalId}"]);

            DB::table('analytics.booking_metrics_hourly')
                ->where('professional_id', $professionalId)
                ->where('hour_start', $hour)
                ->delete();

            $rows = DB::table('analytics.booking_events as e')
                ->where('e.professional_id', $professionalId)
                ->where('e.occurred_at', '>=', $hour)
                ->where('e.occurred_at', '<', $hourEnd)
                ->select([
                    'e.currency_code',
                    DB::raw('COUNT(*) as bookings_count'),
                    DB::raw('COALESCE(SUM(e.amount_paid_cents), 0) as total_spent_cents'),
                    DB::raw('COALESCE(SUM(CASE WHEN e.amount_paid_cents > 0 THEN 1 ELSE 0 END), 0) as paid_bookings_count'),
                    DB::raw("COUNT(DISTINCT NULLIF(lower(trim(e.customer_email)), '')) as customers_count"),
                ])
                ->groupBy('e.currency_code')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $inserts = $rows->map(static fn ($row): array => [
                'hour_start' => $hour,
                'professional_id' => $professionalId,
                'currency_code' => (string) $row->currency_code,
                'timezone' => $timezone,
                'bookings_count' => (int) ($row->bookings_count ?? 0),
                'total_spent_cents' => (int) ($row->total_spent_cents ?? 0),
                'paid_bookings_count' => (int) ($row->paid_bookings_count ?? 0),
                'customers_count' => (int) ($row->customers_count ?? 0),
                'updated_at' => $now,
            ])->values()->all();

            DB::table('analytics.booking_metrics_hourly')->insert($inserts);
        });
    }

    public function rebuildProfessionalDay(string $professionalId, string $day): void
    {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $day = Carbon::parse($day)->toDateString();
        $timezone = $this->professionalTimezone($professionalId);
        $utcFrom = Carbon::parse($day, $timezone)->startOfDay()->utc();
        $utcTo = Carbon::parse($day, $timezone)->endOfDay()->utc();
        $now = now();

        DB::transaction(function () use ($professionalId, $day, $timezone, $now, $utcFrom, $utcTo): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["analytics-rebuild:{$professionalId}"]);

            DB::table('analytics.booking_metrics_daily')
                ->where('professional_id', $professionalId)
                ->where('day', $day)
                ->delete();

            $rows = DB::table('analytics.booking_events as e')
                ->where('e.professional_id', $professionalId)
                ->whereBetween('e.occurred_at', [$utcFrom, $utcTo])
                ->select([
                    'e.currency_code',
                    DB::raw('COUNT(*) as bookings_count'),
                    DB::raw('COALESCE(SUM(e.amount_paid_cents), 0) as total_spent_cents'),
                    DB::raw('COALESCE(SUM(CASE WHEN e.amount_paid_cents > 0 THEN 1 ELSE 0 END), 0) as paid_bookings_count'),
                    DB::raw("COUNT(DISTINCT NULLIF(lower(trim(e.customer_email)), '')) as customers_count"),
                ])
                ->groupBy('e.currency_code')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $inserts = $rows->map(static fn ($row): array => [
                'day' => $day,
                'professional_id' => $professionalId,
                'currency_code' => (string) $row->currency_code,
                'timezone' => $timezone,
                'bookings_count' => (int) ($row->bookings_count ?? 0),
                'total_spent_cents' => (int) ($row->total_spent_cents ?? 0),
                'paid_bookings_count' => (int) ($row->paid_bookings_count ?? 0),
                'customers_count' => (int) ($row->customers_count ?? 0),
                'updated_at' => $now,
            ])->values()->all();

            DB::table('analytics.booking_metrics_daily')->insert($inserts);
        });
    }
}
