<?php

namespace App\Services\Store;

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderAnalyticsAggregateService
{
    public function rebuildBrandDay(string $brandProfessionalId, string $day): void
    {
        $brandProfessionalId = trim($brandProfessionalId);
        if ($brandProfessionalId === '') {
            return;
        }

        $day = Carbon::parse($day)->toDateString();
        $timezone = $this->professionalTimezone($brandProfessionalId);
        $utcFrom = Carbon::parse($day, $timezone)->startOfDay()->utc();
        $utcTo = Carbon::parse($day, $timezone)->endOfDay()->utc();
        $now = now();

        DB::transaction(function () use ($brandProfessionalId, $day, $timezone, $now, $utcFrom, $utcTo): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["analytics-rebuild:{$brandProfessionalId}"]);

            $this->deleteBrandDayRows($brandProfessionalId, $day);

            $brandMetricsRows = DB::table('retail.orders as o')
                ->where('o.brand_professional_id', $brandProfessionalId)
                ->whereBetween('o.ordered_at', [$utcFrom, $utcTo])
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

            $brandMetricInserts = $brandMetricsRows->map(fn ($row): array => [
                'day' => $day,
                'brand_professional_id' => $brandProfessionalId,
                'currency_code' => (string) $row->currency_code,
                'timezone' => $timezone,
                'orders_count' => (int) ($row->orders_count ?? 0),
                'gross_cents' => (int) ($row->gross_cents ?? 0),
                'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                'returned_cents' => (int) ($row->returned_cents ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'updated_at' => $now,
            ])->values()->all();

            if ($brandMetricInserts !== []) {
                DB::table('analytics.brand_metrics_daily')->insert($brandMetricInserts);
            }

            $ordersByAffiliate = DB::table('retail.orders as o')
                ->where('o.brand_professional_id', $brandProfessionalId)
                ->whereBetween('o.ordered_at', [$utcFrom, $utcTo])
                ->select([
                    'o.affiliate_professional_id',
                    'o.currency_code',
                    DB::raw('COUNT(*) as orders_count'),
                    DB::raw('COALESCE(SUM(o.gross_cents), 0) as gross_cents'),
                    DB::raw('COALESCE(SUM(o.refunded_cents), 0) as refunded_cents'),
                    DB::raw('COALESCE(SUM(o.returned_cents), 0) as returned_cents'),
                    DB::raw('COALESCE(SUM(o.net_cents), 0) as net_cents'),
                ])
                ->groupBy('o.affiliate_professional_id', 'o.currency_code')
                ->get();

            // Unique customers per affiliate for this brand+day (currency-agnostic).
            $customersByAffiliate = DB::table('retail.orders as o')
                ->where('o.brand_professional_id', $brandProfessionalId)
                ->whereBetween('o.ordered_at', [$utcFrom, $utcTo])
                ->whereNotNull('o.customer_email_hash')
                ->select([
                    'o.affiliate_professional_id',
                    DB::raw('COUNT(DISTINCT o.customer_email_hash) as customers_count'),
                ])
                ->groupBy('o.affiliate_professional_id')
                ->get()
                ->keyBy('affiliate_professional_id');

            $commissionByAffiliate = DB::table('retail.commission_ledger_entries as l')
                ->where('l.brand_professional_id', $brandProfessionalId)
                ->whereBetween('l.occurred_at', [$utcFrom, $utcTo])
                ->select([
                    'l.affiliate_professional_id',
                    'l.currency_code',
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents ELSE 0 END), 0) as accrual_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'reversal' THEN ABS(l.amount_cents) ELSE 0 END), 0) as reversed_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents WHEN l.entry_type = 'reversal' THEN l.amount_cents ELSE 0 END), 0) as net_commission_cents"),
                ])
                ->groupBy('l.affiliate_professional_id', 'l.currency_code')
                ->get();

            $influencerMap = [];
            foreach ($ordersByAffiliate as $row) {
                $key = $this->tupleKey([
                    (string) $row->affiliate_professional_id,
                    (string) $row->currency_code,
                ]);

                $affiliateId = (string) $row->affiliate_professional_id;
                $influencerMap[$key] = [
                    'affiliate_professional_id' => $affiliateId,
                    'currency_code' => (string) $row->currency_code,
                    'orders_count' => (int) ($row->orders_count ?? 0),
                    'gross_cents' => (int) ($row->gross_cents ?? 0),
                    'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                    'returned_cents' => (int) ($row->returned_cents ?? 0),
                    'net_cents' => (int) ($row->net_cents ?? 0),
                    'commission_accrued_cents' => 0,
                    'commission_reversed_cents' => 0,
                    'commission_net_cents' => 0,
                    'customers_count' => (int) ($customersByAffiliate[$affiliateId]->customers_count ?? 0),
                ];
            }

            foreach ($commissionByAffiliate as $row) {
                $key = $this->tupleKey([
                    (string) $row->affiliate_professional_id,
                    (string) $row->currency_code,
                ]);

                $existing = $influencerMap[$key] ?? [
                    'affiliate_professional_id' => (string) $row->affiliate_professional_id,
                    'currency_code' => (string) $row->currency_code,
                    'orders_count' => 0,
                    'gross_cents' => 0,
                    'refunded_cents' => 0,
                    'returned_cents' => 0,
                    'net_cents' => 0,
                    'commission_accrued_cents' => 0,
                    'commission_reversed_cents' => 0,
                    'commission_net_cents' => 0,
                    'customers_count' => (int) ($customersByAffiliate[(string) $row->affiliate_professional_id]->customers_count ?? 0),
                ];

                $existing['commission_accrued_cents'] = (int) ($row->accrual_cents ?? 0);
                $existing['commission_reversed_cents'] = (int) ($row->reversed_cents ?? 0);
                $existing['commission_net_cents'] = (int) ($row->net_commission_cents ?? 0);

                $influencerMap[$key] = $existing;
            }

            $brandInfluencerInserts = collect(array_values($influencerMap))
                ->map(fn (array $row): array => [
                    'day' => $day,
                    'brand_professional_id' => $brandProfessionalId,
                    'affiliate_professional_id' => $row['affiliate_professional_id'],
                    'currency_code' => $row['currency_code'],
                    'timezone' => $timezone,
                    'orders_count' => (int) $row['orders_count'],
                    'gross_cents' => (int) $row['gross_cents'],
                    'refunded_cents' => (int) $row['refunded_cents'],
                    'returned_cents' => (int) $row['returned_cents'],
                    'net_cents' => (int) $row['net_cents'],
                    'commission_accrued_cents' => (int) $row['commission_accrued_cents'],
                    'commission_reversed_cents' => (int) $row['commission_reversed_cents'],
                    'commission_net_cents' => (int) $row['commission_net_cents'],
                    'customers_count' => (int) $row['customers_count'],
                    'updated_at' => $now,
                ])
                ->values()
                ->all();

            if ($brandInfluencerInserts !== []) {
                DB::table('analytics.brand_influencer_daily')->insert($brandInfluencerInserts);
            }

            $productRows = DB::table('retail.order_items as i')
                ->join('retail.orders as o', 'o.id', '=', 'i.order_id')
                ->leftJoin('retail.brand_products as bp', 'bp.id', '=', 'i.brand_product_id')
                ->where('o.brand_professional_id', $brandProfessionalId)
                ->whereNotNull('i.brand_product_id')
                ->whereBetween('o.ordered_at', [$utcFrom, $utcTo])
                ->select([
                    'i.brand_product_id',
                    'o.currency_code',
                    'bp.metadata',
                    DB::raw('COALESCE(SUM(i.quantity), 0) as units_sold'),
                    DB::raw('COUNT(DISTINCT o.id) as orders_count'),
                    DB::raw('COALESCE(SUM(i.gross_line_cents), 0) as gross_cents'),
                    DB::raw('COALESCE(SUM(i.refunded_line_cents), 0) as refunded_cents'),
                    DB::raw('COALESCE(SUM(i.returned_line_cents), 0) as returned_cents'),
                    DB::raw('COALESCE(SUM(i.net_line_cents), 0) as net_cents'),
                ])
                ->groupBy('i.brand_product_id', 'o.currency_code', 'bp.metadata')
                ->get();

            $commissionByProduct = DB::table('retail.commission_ledger_entries as l')
                ->join('retail.order_items as i', 'i.id', '=', 'l.order_item_id')
                ->where('l.brand_professional_id', $brandProfessionalId)
                ->whereNotNull('i.brand_product_id')
                ->whereBetween('l.occurred_at', [$utcFrom, $utcTo])
                ->select([
                    'i.brand_product_id',
                    'l.currency_code',
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents WHEN l.entry_type = 'reversal' THEN l.amount_cents ELSE 0 END), 0) as commission_net_cents"),
                ])
                ->groupBy('i.brand_product_id', 'l.currency_code')
                ->get();

            $productMap = [];
            foreach ($productRows as $row) {
                $key = $this->tupleKey([
                    (string) $row->brand_product_id,
                    (string) $row->currency_code,
                ]);

                $metadata = $this->decodeMetadata($row->metadata ?? null);
                $productMap[$key] = [
                    'brand_product_id' => (string) $row->brand_product_id,
                    'currency_code' => (string) $row->currency_code,
                    'category' => $this->nullableString(Arr::get($metadata, 'category')),
                    'collection' => $this->nullableString(Arr::get($metadata, 'collection')),
                    'units_sold' => (int) ($row->units_sold ?? 0),
                    'orders_count' => (int) ($row->orders_count ?? 0),
                    'gross_cents' => (int) ($row->gross_cents ?? 0),
                    'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                    'returned_cents' => (int) ($row->returned_cents ?? 0),
                    'net_cents' => (int) ($row->net_cents ?? 0),
                    'commission_net_cents' => 0,
                ];
            }

            foreach ($commissionByProduct as $row) {
                $key = $this->tupleKey([
                    (string) $row->brand_product_id,
                    (string) $row->currency_code,
                ]);

                if (! isset($productMap[$key])) {
                    continue;
                }

                $productMap[$key]['commission_net_cents'] = (int) ($row->commission_net_cents ?? 0);
            }

            $brandProductInserts = collect(array_values($productMap))
                ->map(fn (array $row): array => [
                    'day' => $day,
                    'brand_professional_id' => $brandProfessionalId,
                    'brand_product_id' => $row['brand_product_id'],
                    'category' => $row['category'],
                    'collection' => $row['collection'],
                    'currency_code' => $row['currency_code'],
                    'timezone' => $timezone,
                    'units_sold' => (int) $row['units_sold'],
                    'orders_count' => (int) $row['orders_count'],
                    'gross_cents' => (int) $row['gross_cents'],
                    'refunded_cents' => (int) $row['refunded_cents'],
                    'returned_cents' => (int) $row['returned_cents'],
                    'net_cents' => (int) $row['net_cents'],
                    'commission_net_cents' => (int) $row['commission_net_cents'],
                    'updated_at' => $now,
                ])
                ->values()
                ->all();

            if ($brandProductInserts !== []) {
                DB::table('analytics.brand_product_daily')->insert($brandProductInserts);
            }

            $influencerProductRows = DB::table('retail.order_items as i')
                ->join('retail.orders as o', 'o.id', '=', 'i.order_id')
                ->leftJoin('retail.brand_products as bp', 'bp.id', '=', 'i.brand_product_id')
                ->where('o.brand_professional_id', $brandProfessionalId)
                ->whereNotNull('i.brand_product_id')
                ->whereBetween('o.ordered_at', [$utcFrom, $utcTo])
                ->select([
                    'o.affiliate_professional_id',
                    'i.brand_product_id',
                    'o.currency_code',
                    'bp.metadata',
                    DB::raw('COALESCE(SUM(i.quantity), 0) as units_sold'),
                    DB::raw('COUNT(DISTINCT o.id) as orders_count'),
                    DB::raw('COALESCE(SUM(i.gross_line_cents), 0) as gross_cents'),
                    DB::raw('COALESCE(SUM(i.refunded_line_cents), 0) as refunded_cents'),
                    DB::raw('COALESCE(SUM(i.returned_line_cents), 0) as returned_cents'),
                    DB::raw('COALESCE(SUM(i.net_line_cents), 0) as net_cents'),
                ])
                ->groupBy('o.affiliate_professional_id', 'i.brand_product_id', 'o.currency_code', 'bp.metadata')
                ->get();

            $commissionByInfluencerProduct = DB::table('retail.commission_ledger_entries as l')
                ->join('retail.order_items as i', 'i.id', '=', 'l.order_item_id')
                ->where('l.brand_professional_id', $brandProfessionalId)
                ->whereNotNull('i.brand_product_id')
                ->whereBetween('l.occurred_at', [$utcFrom, $utcTo])
                ->select([
                    'l.affiliate_professional_id',
                    'i.brand_product_id',
                    'l.currency_code',
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents WHEN l.entry_type = 'reversal' THEN l.amount_cents ELSE 0 END), 0) as commission_net_cents"),
                ])
                ->groupBy('l.affiliate_professional_id', 'i.brand_product_id', 'l.currency_code')
                ->get();

            $influencerProductMap = [];
            foreach ($influencerProductRows as $row) {
                $key = $this->tupleKey([
                    (string) $row->affiliate_professional_id,
                    (string) $row->brand_product_id,
                    (string) $row->currency_code,
                ]);

                $metadata = $this->decodeMetadata($row->metadata ?? null);
                $influencerProductMap[$key] = [
                    'affiliate_professional_id' => (string) $row->affiliate_professional_id,
                    'brand_product_id' => (string) $row->brand_product_id,
                    'currency_code' => (string) $row->currency_code,
                    'category' => $this->nullableString(Arr::get($metadata, 'category')),
                    'collection' => $this->nullableString(Arr::get($metadata, 'collection')),
                    'units_sold' => (int) ($row->units_sold ?? 0),
                    'orders_count' => (int) ($row->orders_count ?? 0),
                    'gross_cents' => (int) ($row->gross_cents ?? 0),
                    'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                    'returned_cents' => (int) ($row->returned_cents ?? 0),
                    'net_cents' => (int) ($row->net_cents ?? 0),
                    'commission_net_cents' => 0,
                ];
            }

            foreach ($commissionByInfluencerProduct as $row) {
                $key = $this->tupleKey([
                    (string) $row->affiliate_professional_id,
                    (string) $row->brand_product_id,
                    (string) $row->currency_code,
                ]);

                if (! isset($influencerProductMap[$key])) {
                    continue;
                }

                $influencerProductMap[$key]['commission_net_cents'] = (int) ($row->commission_net_cents ?? 0);
            }

            $brandInfluencerProductInserts = collect(array_values($influencerProductMap))
                ->map(fn (array $row): array => [
                    'day' => $day,
                    'brand_professional_id' => $brandProfessionalId,
                    'affiliate_professional_id' => $row['affiliate_professional_id'],
                    'brand_product_id' => $row['brand_product_id'],
                    'category' => $row['category'],
                    'collection' => $row['collection'],
                    'currency_code' => $row['currency_code'],
                    'timezone' => $timezone,
                    'units_sold' => (int) $row['units_sold'],
                    'orders_count' => (int) $row['orders_count'],
                    'gross_cents' => (int) $row['gross_cents'],
                    'refunded_cents' => (int) $row['refunded_cents'],
                    'returned_cents' => (int) $row['returned_cents'],
                    'net_cents' => (int) $row['net_cents'],
                    'commission_net_cents' => (int) $row['commission_net_cents'],
                    'updated_at' => $now,
                ])
                ->values()
                ->all();

            if ($brandInfluencerProductInserts !== []) {
                DB::table('analytics.brand_influencer_product_daily')->insert($brandInfluencerProductInserts);
            }

            $commissionDailyRows = DB::table('retail.commission_ledger_entries as l')
                ->where('l.brand_professional_id', $brandProfessionalId)
                ->whereBetween('l.occurred_at', [$utcFrom, $utcTo])
                ->select([
                    'l.affiliate_professional_id',
                    'l.status as payout_status',
                    'l.currency_code',
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents ELSE 0 END), 0) as accrual_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'reversal' THEN ABS(l.amount_cents) ELSE 0 END), 0) as reversal_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'payout' THEN ABS(l.amount_cents) ELSE 0 END), 0) as payout_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents WHEN l.entry_type IN ('reversal', 'payout') THEN l.amount_cents ELSE 0 END), 0) as net_outstanding_cents"),
                    DB::raw('COUNT(*) as entries_count'),
                ])
                ->groupBy('l.affiliate_professional_id', 'l.status', 'l.currency_code')
                ->get();

            $brandCommissionInserts = $commissionDailyRows->map(fn ($row): array => [
                'day' => $day,
                'brand_professional_id' => $brandProfessionalId,
                'affiliate_professional_id' => (string) $row->affiliate_professional_id,
                'payout_status' => (string) $row->payout_status,
                'currency_code' => (string) $row->currency_code,
                'timezone' => $timezone,
                'accrual_cents' => (int) ($row->accrual_cents ?? 0),
                'reversal_cents' => (int) ($row->reversal_cents ?? 0),
                'payout_cents' => (int) ($row->payout_cents ?? 0),
                'net_outstanding_cents' => (int) ($row->net_outstanding_cents ?? 0),
                'entries_count' => (int) ($row->entries_count ?? 0),
                'updated_at' => $now,
            ])->values()->all();

            if ($brandCommissionInserts !== []) {
                DB::table('analytics.brand_commission_daily')->insert($brandCommissionInserts);
            }

        });
    }

    public function rebuildProfessionalDay(string $affiliateProfessionalId, string $day): void
    {
        $affiliateProfessionalId = trim($affiliateProfessionalId);
        if ($affiliateProfessionalId === '') {
            return;
        }

        $day = Carbon::parse($day)->toDateString();
        $timezone = $this->professionalTimezone($affiliateProfessionalId);
        $utcFrom = Carbon::parse($day, $timezone)->startOfDay()->utc();
        $utcTo = Carbon::parse($day, $timezone)->endOfDay()->utc();
        $now = now();

        DB::transaction(function () use ($affiliateProfessionalId, $day, $timezone, $now, $utcFrom, $utcTo): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["analytics-rebuild:{$affiliateProfessionalId}"]);

            DB::table('analytics.professional_metrics_daily')
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('day', $day)
                ->delete();

            DB::table('analytics.professional_product_daily')
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('day', $day)
                ->delete();

            DB::table('analytics.professional_customer_daily')
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('day', $day)
                ->delete();

            $metricsRows = DB::table('retail.orders as o')
                ->where('o.affiliate_professional_id', $affiliateProfessionalId)
                ->whereBetween('o.ordered_at', [$utcFrom, $utcTo])
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
                ->whereBetween('l.occurred_at', [$utcFrom, $utcTo])
                ->select([
                    'l.currency_code',
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents ELSE 0 END), 0) as accrued_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'reversal' THEN ABS(l.amount_cents) ELSE 0 END), 0) as reversed_cents"),
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'payout' THEN ABS(l.amount_cents) ELSE 0 END), 0) as paid_cents"),
                ])
                ->groupBy('l.currency_code')
                ->get();

            $metricsMap = [];
            foreach ($metricsRows as $row) {
                $metricsMap[(string) $row->currency_code] = [
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
                $existing = $metricsMap[$currency] ?? [
                    'orders_count' => 0,
                    'gross_cents' => 0,
                    'refunded_cents' => 0,
                    'returned_cents' => 0,
                    'net_cents' => 0,
                    'commission_accrued_cents' => 0,
                    'commission_reversed_cents' => 0,
                    'commission_paid_cents' => 0,
                ];

                $existing['commission_accrued_cents'] = (int) ($row->accrued_cents ?? 0);
                $existing['commission_reversed_cents'] = (int) ($row->reversed_cents ?? 0);
                $existing['commission_paid_cents'] = (int) ($row->paid_cents ?? 0);

                $metricsMap[$currency] = $existing;
            }

            $professionalMetricInserts = [];
            foreach ($metricsMap as $currency => $row) {
                $professionalMetricInserts[] = [
                    'day' => $day,
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

            if ($professionalMetricInserts !== []) {
                DB::table('analytics.professional_metrics_daily')->insert($professionalMetricInserts);
            }

            $productRows = DB::table('retail.order_items as i')
                ->join('retail.orders as o', 'o.id', '=', 'i.order_id')
                ->leftJoin('retail.brand_products as bp', 'bp.id', '=', 'i.brand_product_id')
                ->where('o.affiliate_professional_id', $affiliateProfessionalId)
                ->whereNotNull('i.brand_product_id')
                ->whereBetween('o.ordered_at', [$utcFrom, $utcTo])
                ->select([
                    'o.brand_professional_id',
                    'i.brand_product_id',
                    'o.currency_code',
                    'bp.metadata',
                    DB::raw('COALESCE(SUM(i.quantity), 0) as units_sold'),
                    DB::raw('COUNT(DISTINCT o.id) as orders_count'),
                    DB::raw('COALESCE(SUM(i.net_line_cents), 0) as net_cents'),
                ])
                ->groupBy('o.brand_professional_id', 'i.brand_product_id', 'o.currency_code', 'bp.metadata')
                ->get();

            $commissionByProduct = DB::table('retail.commission_ledger_entries as l')
                ->join('retail.order_items as i', 'i.id', '=', 'l.order_item_id')
                ->join('retail.orders as o', 'o.id', '=', 'i.order_id')
                ->where('l.affiliate_professional_id', $affiliateProfessionalId)
                ->whereNotNull('i.brand_product_id')
                ->whereBetween('l.occurred_at', [$utcFrom, $utcTo])
                ->select([
                    'o.brand_professional_id',
                    'i.brand_product_id',
                    'l.currency_code',
                    DB::raw("COALESCE(SUM(CASE WHEN l.entry_type = 'accrual' THEN l.amount_cents WHEN l.entry_type = 'reversal' THEN l.amount_cents ELSE 0 END), 0) as commission_net_cents"),
                ])
                ->groupBy('o.brand_professional_id', 'i.brand_product_id', 'l.currency_code')
                ->get();

            $productMap = [];
            foreach ($productRows as $row) {
                $key = $this->tupleKey([
                    (string) $row->brand_professional_id,
                    (string) $row->brand_product_id,
                    (string) $row->currency_code,
                ]);

                $metadata = $this->decodeMetadata($row->metadata ?? null);
                $productMap[$key] = [
                    'brand_professional_id' => (string) $row->brand_professional_id,
                    'brand_product_id' => (string) $row->brand_product_id,
                    'currency_code' => (string) $row->currency_code,
                    'category' => $this->nullableString(Arr::get($metadata, 'category')),
                    'collection' => $this->nullableString(Arr::get($metadata, 'collection')),
                    'units_sold' => (int) ($row->units_sold ?? 0),
                    'orders_count' => (int) ($row->orders_count ?? 0),
                    'net_cents' => (int) ($row->net_cents ?? 0),
                    'commission_net_cents' => 0,
                ];
            }

            foreach ($commissionByProduct as $row) {
                $key = $this->tupleKey([
                    (string) $row->brand_professional_id,
                    (string) $row->brand_product_id,
                    (string) $row->currency_code,
                ]);

                if (! isset($productMap[$key])) {
                    continue;
                }

                $productMap[$key]['commission_net_cents'] = (int) ($row->commission_net_cents ?? 0);
            }

            $professionalProductInserts = collect(array_values($productMap))
                ->map(fn (array $row): array => [
                    'day' => $day,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'brand_professional_id' => $row['brand_professional_id'],
                    'brand_product_id' => $row['brand_product_id'],
                    'category' => $row['category'],
                    'collection' => $row['collection'],
                    'currency_code' => $row['currency_code'],
                    'timezone' => $timezone,
                    'units_sold' => (int) $row['units_sold'],
                    'orders_count' => (int) $row['orders_count'],
                    'net_cents' => (int) $row['net_cents'],
                    'commission_net_cents' => (int) $row['commission_net_cents'],
                    'updated_at' => $now,
                ])
                ->values()
                ->all();

            if ($professionalProductInserts !== []) {
                DB::table('analytics.professional_product_daily')->insert($professionalProductInserts);
            }

            // Customer analytics: rank each customer's orders across all time for this affiliate
            // so rn=1 identifies their very first order (new customer), rn>1 is returning.
            $customerRow = DB::selectOne("
                WITH all_ranked AS (
                    SELECT
                        customer_email_hash,
                        ROW_NUMBER() OVER (
                            PARTITION BY customer_email_hash
                            ORDER BY ordered_at
                        ) AS rn,
                        (ordered_at AT TIME ZONE ?)::date AS order_day
                    FROM retail.orders
                    WHERE affiliate_professional_id = ?
                    AND customer_email_hash IS NOT NULL
                )
                SELECT
                    COUNT(DISTINCT customer_email_hash) AS customers_count,
                    COUNT(DISTINCT CASE WHEN rn = 1 THEN customer_email_hash END) AS new_customers_count
                FROM all_ranked
                WHERE order_day = ?
            ", [$timezone, $affiliateProfessionalId, $day]);

            $customersCount = (int) ($customerRow->customers_count ?? 0);
            $newCustomersCount = (int) ($customerRow->new_customers_count ?? 0);

            if ($customersCount > 0) {
                DB::table('analytics.professional_customer_daily')->insert([
                    'day' => $day,
                    'affiliate_professional_id' => $affiliateProfessionalId,
                    'timezone' => $timezone,
                    'customers_count' => $customersCount,
                    'new_customers_count' => $newCustomersCount,
                    'returning_customers_count' => max(0, $customersCount - $newCustomersCount),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    private function deleteBrandDayRows(string $brandProfessionalId, string $day): void
    {
        DB::table('analytics.brand_metrics_daily')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('day', $day)
            ->delete();

        DB::table('analytics.brand_influencer_daily')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('day', $day)
            ->delete();

        DB::table('analytics.brand_product_daily')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('day', $day)
            ->delete();

        DB::table('analytics.brand_influencer_product_daily')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('day', $day)
            ->delete();

        DB::table('analytics.brand_commission_daily')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('day', $day)
            ->delete();

    }

    /** @var array<string, string> */
    private array $timezoneCache = [];

    private function professionalTimezone(string $professionalId): string
    {
        if (isset($this->timezoneCache[$professionalId])) {
            return $this->timezoneCache[$professionalId];
        }

        $timezone = Professional::query()
            ->where('id', $professionalId)
            ->value('timezone');

        $timezone = trim((string) $timezone);
        $resolved = $timezone !== '' ? $timezone : 'UTC';

        $this->timezoneCache[$professionalId] = $resolved;

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function tupleKey(array $parts): string
    {
        return implode('|', $parts);
    }
}
