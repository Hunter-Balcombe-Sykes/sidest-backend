<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Concerns\ResolvesTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Aggregates commerce metrics (orders, revenue, commissions) from commission_ledger_entries into daily and hourly analytics tables.
class CommerceAnalyticsAggregateService
{
    use ResolvesTimezone;

    /**
     * Rebuild all commerce daily aggregates affected by an order.
     * Called after ledger entries are created (Shopify order webhook)
     * and during hourly compaction.
     */
    public function rebuildForOrder(string $brandProfessionalId, string $affiliateProfessionalId, string $day): void
    {
        $this->rebuildProfessionalMetricsDay($affiliateProfessionalId, $day);
        $this->rebuildBrandMetricsDay($brandProfessionalId, $day);
        $this->rebuildBrandAffiliateDailyDay($brandProfessionalId, $affiliateProfessionalId, $day);
        $this->rebuildBrandCommissionDay($brandProfessionalId, $affiliateProfessionalId, $day);
    }

    /**
     * Rebuild both hourly aggregate tables for one brand + affiliate + hour.
     * Called immediately after a Shopify order webhook fires so the last-24h view is current.
     */
    public function rebuildForHour(string $brandProfessionalId, string $affiliateProfessionalId, string $hourStart): void
    {
        $this->rebuildProfessionalMetricsHour($affiliateProfessionalId, $hourStart);
        $this->rebuildBrandMetricsHour($brandProfessionalId, $hourStart);
    }

    /**
     * Rebuild analytics.professional_metrics_hourly for one affiliate + UTC hour.
     * Aggregates across ALL brands the affiliate sells for within that hour.
     */
    public function rebuildProfessionalMetricsHour(string $affiliateProfessionalId, string $hourStart): void
    {
        $hour = Carbon::parse($hourStart)->utc()->startOfHour();
        $hourEnd = $hour->copy()->addHour();
        $timezone = $this->professionalTimezone($affiliateProfessionalId);
        $now = now();

        DB::transaction(function () use ($affiliateProfessionalId, $hour, $hourEnd, $timezone, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["commerce-rebuild:affiliate-hour:{$affiliateProfessionalId}"]);

            DB::table('analytics.professional_metrics_hourly')
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('hour_start', $hour)
                ->delete();

            $rows = $this->queryLedger($hour, $hourEnd)
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->groupBy('currency_code')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $inserts = $rows->map(fn ($row) => [
                'hour_start' => $hour,
                'affiliate_professional_id' => $affiliateProfessionalId,
                'currency_code' => (string) $row->currency_code,
                'timezone' => $timezone,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'refunded_cents' => (int) $row->refunded_cents,
                'returned_cents' => 0,
                'net_cents' => (int) $row->gross_cents - (int) $row->refunded_cents,
                'commission_accrued_cents' => (int) $row->commission_accrued_cents,
                'commission_reversed_cents' => (int) $row->commission_reversed_cents,
                'commission_paid_cents' => 0, // payouts tracked at daily granularity only
                'updated_at' => $now,
            ])->all();

            DB::table('analytics.professional_metrics_hourly')->insert($inserts);
        });
    }

    /**
     * Rebuild analytics.brand_metrics_hourly for one brand + UTC hour.
     * Aggregates across ALL affiliates for that brand within that hour.
     */
    public function rebuildBrandMetricsHour(string $brandProfessionalId, string $hourStart): void
    {
        $hour = Carbon::parse($hourStart)->utc()->startOfHour();
        $hourEnd = $hour->copy()->addHour();
        $timezone = $this->professionalTimezone($brandProfessionalId);
        $now = now();

        DB::transaction(function () use ($brandProfessionalId, $hour, $hourEnd, $timezone, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["commerce-rebuild:brand-hour:{$brandProfessionalId}"]);

            DB::table('analytics.brand_metrics_hourly')
                ->where('brand_professional_id', $brandProfessionalId)
                ->where('hour_start', $hour)
                ->delete();

            $rows = $this->queryLedger($hour, $hourEnd)
                ->where('brand_professional_id', $brandProfessionalId)
                ->groupBy('currency_code')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $inserts = $rows->map(fn ($row) => [
                'hour_start' => $hour,
                'brand_professional_id' => $brandProfessionalId,
                'currency_code' => (string) $row->currency_code,
                'timezone' => $timezone,
                'orders_count' => (int) $row->orders_count,
                'gross_cents' => (int) $row->gross_cents,
                'refunded_cents' => (int) $row->refunded_cents,
                'returned_cents' => 0,
                'net_cents' => (int) $row->gross_cents - (int) $row->refunded_cents,
                'commission_net_cents' => (int) $row->commission_accrued_cents - (int) $row->commission_reversed_cents,
                'updated_at' => $now,
            ])->all();

            DB::table('analytics.brand_metrics_hourly')->insert($inserts);
        });
    }

    /**
     * Rebuild analytics.professional_metrics_daily for one affiliate + day.
     * Aggregates across ALL brands the affiliate sells for.
     */
    public function rebuildProfessionalMetricsDay(string $affiliateProfessionalId, string $day): void
    {
        $day = Carbon::parse($day)->toDateString();
        $timezone = $this->professionalTimezone($affiliateProfessionalId);
        [$utcFrom, $utcTo] = $this->dayBoundsUtc($day, $timezone);
        $now = now();

        DB::transaction(function () use ($affiliateProfessionalId, $day, $timezone, $utcFrom, $utcTo, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["commerce-rebuild:affiliate:{$affiliateProfessionalId}"]);

            DB::table('analytics.professional_metrics_daily')
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('day', $day)
                ->delete();

            $rows = $this->queryLedger($utcFrom, $utcTo)
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->groupBy('currency_code')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $inserts = $rows->map(function ($row) use ($affiliateProfessionalId, $day, $timezone, $utcFrom, $utcTo, $now) {
                $gross = (int) $row->gross_cents;
                $refunded = (int) $row->refunded_cents;
                $paidCents = $this->queryPaidCommission($affiliateProfessionalId, (string) $row->currency_code, $utcFrom, $utcTo);

                return [
                    'day' => $day,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'currency_code' => (string) $row->currency_code,
                    'timezone' => $timezone,
                    'orders_count' => (int) $row->orders_count,
                    'gross_cents' => $gross,
                    'refunded_cents' => $refunded,
                    'returned_cents' => 0,
                    'net_cents' => $gross - $refunded,
                    'commission_accrued_cents' => (int) $row->commission_accrued_cents,
                    'commission_reversed_cents' => (int) $row->commission_reversed_cents,
                    'commission_paid_cents' => $paidCents,
                    'updated_at' => $now,
                ];
            })->all();

            DB::table('analytics.professional_metrics_daily')->insert($inserts);
        });
    }

    /**
     * Rebuild analytics.brand_metrics_daily for one brand + day.
     * Aggregates across ALL affiliates for that brand.
     */
    public function rebuildBrandMetricsDay(string $brandProfessionalId, string $day): void
    {
        $day = Carbon::parse($day)->toDateString();
        $timezone = $this->professionalTimezone($brandProfessionalId);
        [$utcFrom, $utcTo] = $this->dayBoundsUtc($day, $timezone);
        $now = now();

        DB::transaction(function () use ($brandProfessionalId, $day, $timezone, $utcFrom, $utcTo, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["commerce-rebuild:brand:{$brandProfessionalId}"]);

            DB::table('analytics.brand_metrics_daily')
                ->where('brand_professional_id', $brandProfessionalId)
                ->where('day', $day)
                ->delete();

            $rows = $this->queryLedger($utcFrom, $utcTo)
                ->where('brand_professional_id', $brandProfessionalId)
                ->groupBy('currency_code')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $inserts = $rows->map(function ($row) use ($day, $brandProfessionalId, $timezone, $now) {
                $gross = (int) $row->gross_cents;
                $refunded = (int) $row->refunded_cents;

                return [
                    'day' => $day,
                    'brand_professional_id' => $brandProfessionalId,
                    'currency_code' => (string) $row->currency_code,
                    'timezone' => $timezone,
                    'orders_count' => (int) $row->orders_count,
                    'gross_cents' => $gross,
                    'refunded_cents' => $refunded,
                    'returned_cents' => 0,
                    'net_cents' => $gross - $refunded,
                    'updated_at' => $now,
                ];
            })->all();

            DB::table('analytics.brand_metrics_daily')->insert($inserts);
        });
    }

    /**
     * Rebuild analytics.brand_affiliate_daily for one brand + affiliate + day.
     * Per-pair breakdown of orders, revenue, and commissions.
     */
    public function rebuildBrandAffiliateDailyDay(string $brandProfessionalId, string $affiliateProfessionalId, string $day): void
    {
        $day = Carbon::parse($day)->toDateString();
        $timezone = $this->professionalTimezone($brandProfessionalId);
        [$utcFrom, $utcTo] = $this->dayBoundsUtc($day, $timezone);
        $now = now();

        DB::transaction(function () use ($brandProfessionalId, $affiliateProfessionalId, $day, $timezone, $utcFrom, $utcTo, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["commerce-rebuild:pair:{$brandProfessionalId}:{$affiliateProfessionalId}"]);

            DB::table('analytics.brand_affiliate_daily')
                ->where('brand_professional_id', $brandProfessionalId)
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('day', $day)
                ->delete();

            $rows = $this->queryLedger($utcFrom, $utcTo)
                ->where('brand_professional_id', $brandProfessionalId)
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->groupBy('currency_code')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $customersCount = $this->queryCustomersCount($affiliateProfessionalId, $utcFrom, $utcTo);

            $inserts = $rows->map(function ($row) use ($brandProfessionalId, $affiliateProfessionalId, $day, $timezone, $customersCount, $now) {
                $gross = (int) $row->gross_cents;
                $refunded = (int) $row->refunded_cents;

                return [
                    'day' => $day,
                    'brand_professional_id' => $brandProfessionalId,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'currency_code' => (string) $row->currency_code,
                    'timezone' => $timezone,
                    'orders_count' => (int) $row->orders_count,
                    'gross_cents' => $gross,
                    'refunded_cents' => $refunded,
                    'returned_cents' => 0,
                    'net_cents' => $gross - $refunded,
                    'commission_accrued_cents' => (int) $row->commission_accrued_cents,
                    'commission_reversed_cents' => (int) $row->commission_reversed_cents,
                    'commission_net_cents' => (int) $row->commission_accrued_cents - (int) $row->commission_reversed_cents,
                    'customers_count' => $customersCount,
                    'updated_at' => $now,
                ];
            })->all();

            DB::table('analytics.brand_affiliate_daily')->insert($inserts);
        });
    }

    /**
     * Rebuild analytics.brand_commission_daily for one brand + affiliate + day.
     * Groups by payout_status (maps to ledger entry status).
     */
    public function rebuildBrandCommissionDay(string $brandProfessionalId, string $affiliateProfessionalId, string $day): void
    {
        $day = Carbon::parse($day)->toDateString();
        $timezone = $this->professionalTimezone($brandProfessionalId);
        [$utcFrom, $utcTo] = $this->dayBoundsUtc($day, $timezone);
        $now = now();

        DB::transaction(function () use ($brandProfessionalId, $affiliateProfessionalId, $day, $timezone, $utcFrom, $utcTo, $now): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["commerce-rebuild:commission:{$brandProfessionalId}:{$affiliateProfessionalId}"]);

            DB::table('analytics.brand_commission_daily')
                ->where('brand_professional_id', $brandProfessionalId)
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('day', $day)
                ->delete();

            $rows = DB::table('commerce.commission_ledger_entries')
                ->where('brand_professional_id', $brandProfessionalId)
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('occurred_at', '>=', $utcFrom)
                ->where('occurred_at', '<', $utcTo)
                ->whereNull('voided_at')
                ->select([
                    'currency_code',
                    'status',
                    DB::raw("COALESCE(SUM(CASE WHEN entry_type = 'accrual' THEN amount_cents ELSE 0 END), 0) as accrual_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN entry_type = 'reversal' THEN amount_cents ELSE 0 END), 0) as reversal_cents"),
                    DB::raw('COUNT(*) as entries_count'),
                ])
                ->groupBy('currency_code', 'status')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $inserts = $rows->map(fn ($row) => [
                'day' => $day,
                'brand_professional_id' => $brandProfessionalId,
                'affiliate_professional_id' => $affiliateProfessionalId,
                'payout_status' => (string) $row->status,
                'currency_code' => (string) $row->currency_code,
                'timezone' => $timezone,
                'accrual_cents' => (int) $row->accrual_cents,
                'reversal_cents' => (int) $row->reversal_cents,
                'payout_cents' => 0,
                'net_outstanding_cents' => (int) $row->accrual_cents - (int) $row->reversal_cents,
                'entries_count' => (int) $row->entries_count,
                'updated_at' => $now,
            ])->all();

            DB::table('analytics.brand_commission_daily')->insert($inserts);
        });
    }

    /**
     * Sum commission_paid_cents from completed payouts for an affiliate + day.
     */
    private function queryPaidCommission(string $affiliateProfessionalId, string $currencyCode, Carbon $utcFrom, Carbon $utcTo): int
    {
        return (int) DB::table('commerce.commission_payouts')
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->where('currency_code', $currencyCode)
            ->where('status', 'completed')
            ->where('processed_at', '>=', $utcFrom)
            ->where('processed_at', '<', $utcTo)
            ->sum('net_payout_cents');
    }

    /**
     * Count distinct customers for an affiliate on a given day.
     * Uses the contacts table populated by the Shopify order webhook.
     */
    private function queryCustomersCount(string $affiliateProfessionalId, Carbon $utcFrom, Carbon $utcTo): int
    {
        return (int) DB::table('core.customers')
            ->where('professional_id', $affiliateProfessionalId)
            ->where('created_at', '>=', $utcFrom)
            ->where('created_at', '<', $utcTo)
            ->count();
    }

    /**
     * Base query for aggregating ledger entries into order/revenue/commission metrics.
     * Gross cents are reconstructed from calculation_metadata (line_price * quantity).
     * Refunded cents are calculated from reversal entries (refund_subtotal in metadata).
     */
    private function queryLedger(Carbon $utcFrom, Carbon $utcTo): \Illuminate\Database\Query\Builder
    {
        return DB::table('commerce.commission_ledger_entries')
            ->where('occurred_at', '>=', $utcFrom)
            ->where('occurred_at', '<', $utcTo)
            ->whereNull('voided_at')
            ->select([
                'currency_code',
                DB::raw('COUNT(DISTINCT shopify_order_id) as orders_count'),
                DB::raw("COALESCE(SUM(CASE WHEN entry_type = 'accrual' THEN ROUND(COALESCE(calculation_metadata->>'line_price', '0')::numeric * COALESCE(calculation_metadata->>'quantity', '0')::integer * 100) ELSE 0 END), 0) as gross_cents"),
                DB::raw("COALESCE(SUM(CASE WHEN entry_type = 'reversal' THEN ROUND(COALESCE(calculation_metadata->>'refund_subtotal', '0')::numeric * 100) ELSE 0 END), 0) as refunded_cents"),
                DB::raw("COALESCE(SUM(CASE WHEN entry_type = 'accrual' THEN amount_cents ELSE 0 END), 0) as commission_accrued_cents"),
                DB::raw("COALESCE(SUM(CASE WHEN entry_type = 'reversal' THEN ABS(amount_cents) ELSE 0 END), 0) as commission_reversed_cents"),
            ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function dayBoundsUtc(string $day, string $timezone): array
    {
        return [
            Carbon::parse($day, $timezone)->startOfDay()->utc(),
            Carbon::parse($day, $timezone)->endOfDay()->utc()->addSecond(),
        ];
    }
}
