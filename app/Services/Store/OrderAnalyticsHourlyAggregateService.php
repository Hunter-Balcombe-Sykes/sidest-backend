<?php

namespace App\Services\Store;

use App\Services\Analytics\Concerns\ResolvesTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderAnalyticsHourlyAggregateService
{
    use ResolvesTimezone;
    public function rebuildBrandHour(string $brandProfessionalId, Carbon|string $hourStart): void
    {
        $brandProfessionalId = trim($brandProfessionalId);
        if ($brandProfessionalId === '') {
            return;
        }

        $hour = $this->normalizeHour($hourStart);
        $hourEnd = $hour->copy()->addHour();
        $timezone = $this->professionalTimezone($brandProfessionalId);
        $now = now();

        DB::transaction(function () use ($brandProfessionalId, $hour, $hourEnd, $timezone, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["analytics-rebuild:{$brandProfessionalId}"]);

            DB::table('analytics.brand_metrics_hourly')
                ->where('brand_professional_id', $brandProfessionalId)
                ->where('hour_start', $hour)
                ->delete();

            $orderRows = DB::table('retail.orders as o')
                ->where('o.brand_professional_id', $brandProfessionalId)
                ->where('o.ordered_at', '>=', $hour)
                ->where('o.ordered_at', '<', $hourEnd)
                ->select([
                    'o.currency_code',
                    DB::raw('COUNT(*) as orders_count'),
                    DB::raw('COALESCE(SUM(o.gross_cents), 0) as gross_cents'),
                    DB::raw('COALESCE(SUM(o.refunded_cents), 0) as refunded_cents'),
                    DB::raw('COALESCE(SUM(o.returned_cents), 0) as returned_cents'),
                    DB::raw('COALESCE(SUM(o.net_cents), 0) as net_cents'),
                ])
                ->groupBy('o.currency_code')
                ->get();

            $commissionRows = DB::table('retail.commission_ledger_entries as l')
                ->where('l.brand_professional_id', $brandProfessionalId)
                ->where('l.occurred_at', '>=', $hour)
                ->where('l.occurred_at', '<', $hourEnd)
                ->select([
                    'l.currency_code',
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents WHEN l.entry_type = 'reversal' THEN l.amount_cents ELSE 0 END), 0) as commission_net_cents"),
                ])
                ->groupBy('l.currency_code')
                ->get();

            $map = [];

            foreach ($orderRows as $row) {
                $currency = (string) $row->currency_code;
                $map[$currency] = [
                    'orders_count' => (int) ($row->orders_count ?? 0),
                    'gross_cents' => (int) ($row->gross_cents ?? 0),
                    'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                    'returned_cents' => (int) ($row->returned_cents ?? 0),
                    'net_cents' => (int) ($row->net_cents ?? 0),
                    'commission_net_cents' => 0,
                ];
            }

            foreach ($commissionRows as $row) {
                $currency = (string) $row->currency_code;
                $existing = $map[$currency] ?? [
                    'orders_count' => 0,
                    'gross_cents' => 0,
                    'refunded_cents' => 0,
                    'returned_cents' => 0,
                    'net_cents' => 0,
                    'commission_net_cents' => 0,
                ];

                $existing['commission_net_cents'] = (int) ($row->commission_net_cents ?? 0);
                $map[$currency] = $existing;
            }

            if ($map === []) {
                return;
            }

            $inserts = [];
            foreach ($map as $currency => $row) {
                $inserts[] = [
                    'hour_start' => $hour,
                    'brand_professional_id' => $brandProfessionalId,
                    'currency_code' => $currency,
                    'timezone' => $timezone,
                    'orders_count' => (int) $row['orders_count'],
                    'gross_cents' => (int) $row['gross_cents'],
                    'refunded_cents' => (int) $row['refunded_cents'],
                    'returned_cents' => (int) $row['returned_cents'],
                    'net_cents' => (int) $row['net_cents'],
                    'commission_net_cents' => (int) $row['commission_net_cents'],
                    'updated_at' => $now,
                ];
            }

            DB::table('analytics.brand_metrics_hourly')->insert($inserts);
        });
    }

    public function rebuildProfessionalHour(string $affiliateProfessionalId, Carbon|string $hourStart): void
    {
        $affiliateProfessionalId = trim($affiliateProfessionalId);
        if ($affiliateProfessionalId === '') {
            return;
        }

        $hour = $this->normalizeHour($hourStart);
        $hourEnd = $hour->copy()->addHour();
        $timezone = $this->professionalTimezone($affiliateProfessionalId);
        $now = now();

        DB::transaction(function () use ($affiliateProfessionalId, $hour, $hourEnd, $timezone, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["analytics-rebuild:{$affiliateProfessionalId}"]);

            DB::table('analytics.professional_metrics_hourly')
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('hour_start', $hour)
                ->delete();

            $metricRows = DB::table('retail.orders as o')
                ->where('o.affiliate_professional_id', $affiliateProfessionalId)
                ->where('o.ordered_at', '>=', $hour)
                ->where('o.ordered_at', '<', $hourEnd)
                ->select([
                    'o.currency_code',
                    DB::raw('COUNT(*) as orders_count'),
                    DB::raw('COALESCE(SUM(o.gross_cents), 0) as gross_cents'),
                    DB::raw('COALESCE(SUM(o.refunded_cents), 0) as refunded_cents'),
                    DB::raw('COALESCE(SUM(o.returned_cents), 0) as returned_cents'),
                    DB::raw('COALESCE(SUM(o.net_cents), 0) as net_cents'),
                ])
                ->groupBy('o.currency_code')
                ->get();

            $commissionRows = DB::table('retail.commission_ledger_entries as l')
                ->where('l.affiliate_professional_id', $affiliateProfessionalId)
                ->where('l.occurred_at', '>=', $hour)
                ->where('l.occurred_at', '<', $hourEnd)
                ->select([
                    'l.currency_code',
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents ELSE 0 END), 0) as commission_accrued_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'reversal' THEN ABS(l.amount_cents) ELSE 0 END), 0) as commission_reversed_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'payout' THEN ABS(l.amount_cents) ELSE 0 END), 0) as commission_paid_cents"),
                ])
                ->groupBy('l.currency_code')
                ->get();

            $map = [];

            foreach ($metricRows as $row) {
                $currency = (string) $row->currency_code;
                $map[$currency] = [
                    'orders_count' => (int) ($row->orders_count ?? 0),
                    'gross_cents' => (int) ($row->gross_cents ?? 0),
                    'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                    'returned_cents' => (int) ($row->returned_cents ?? 0),
                    'net_cents' => (int) ($row->net_cents ?? 0),
                    'commission_accrued_cents' => 0,
                    'commission_reversed_cents' => 0,
                    'commission_paid_cents' => 0,
                ];
            }

            foreach ($commissionRows as $row) {
                $currency = (string) $row->currency_code;
                $existing = $map[$currency] ?? [
                    'orders_count' => 0,
                    'gross_cents' => 0,
                    'refunded_cents' => 0,
                    'returned_cents' => 0,
                    'net_cents' => 0,
                    'commission_accrued_cents' => 0,
                    'commission_reversed_cents' => 0,
                    'commission_paid_cents' => 0,
                ];

                $existing['commission_accrued_cents'] = (int) ($row->commission_accrued_cents ?? 0);
                $existing['commission_reversed_cents'] = (int) ($row->commission_reversed_cents ?? 0);
                $existing['commission_paid_cents'] = (int) ($row->commission_paid_cents ?? 0);

                $map[$currency] = $existing;
            }

            if ($map === []) {
                return;
            }

            $inserts = [];
            foreach ($map as $currency => $row) {
                $inserts[] = [
                    'hour_start' => $hour,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'currency_code' => $currency,
                    'timezone' => $timezone,
                    'orders_count' => (int) $row['orders_count'],
                    'gross_cents' => (int) $row['gross_cents'],
                    'refunded_cents' => (int) $row['refunded_cents'],
                    'returned_cents' => (int) $row['returned_cents'],
                    'net_cents' => (int) $row['net_cents'],
                    'commission_accrued_cents' => (int) $row['commission_accrued_cents'],
                    'commission_reversed_cents' => (int) $row['commission_reversed_cents'],
                    'commission_paid_cents' => (int) $row['commission_paid_cents'],
                    'updated_at' => $now,
                ];
            }

            DB::table('analytics.professional_metrics_hourly')->insert($inserts);
        });
    }

    private function normalizeHour(Carbon|string $hourStart): Carbon
    {
        return Carbon::parse($hourStart)->utc()->startOfHour();
    }
}
