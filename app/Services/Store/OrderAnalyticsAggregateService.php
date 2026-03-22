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
        $now = now();

        DB::transaction(function () use ($brandProfessionalId, $day, $timezone, $now): void {
            $this->deleteBrandDayRows($brandProfessionalId, $day);

            $brandMetricsRows = DB::table('retail.orders as o')
                ->where('o.brand_professional_id', $brandProfessionalId)
                ->whereRaw('(o.ordered_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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
                ->whereRaw('(o.ordered_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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

            $commissionByAffiliate = DB::table('retail.commission_ledger_entries as l')
                ->where('l.brand_professional_id', $brandProfessionalId)
                ->whereRaw('(l.occurred_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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

                $influencerMap[$key] = [
                    'affiliate_professional_id' => (string) $row->affiliate_professional_id,
                    'currency_code' => (string) $row->currency_code,
                    'orders_count' => (int) ($row->orders_count ?? 0),
                    'gross_cents' => (int) ($row->gross_cents ?? 0),
                    'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                    'returned_cents' => (int) ($row->returned_cents ?? 0),
                    'net_cents' => (int) ($row->net_cents ?? 0),
                    'commission_accrued_cents' => 0,
                    'commission_reversed_cents' => 0,
                    'commission_net_cents' => 0,
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
                ->whereRaw('(o.ordered_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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
                ->whereRaw('(l.occurred_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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
                ->whereRaw('(o.ordered_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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
                ->whereRaw('(l.occurred_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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
                ->whereRaw('(l.occurred_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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

            $payoutRows = DB::table('retail.payout_runs as p')
                ->where('p.brand_professional_id', $brandProfessionalId)
                ->whereRaw('(COALESCE(p.executed_at, p.scheduled_for, p.created_at) AT TIME ZONE ?)::date = ?', [$timezone, $day])
                ->select([
                    'p.status as payout_status',
                    'p.currency_code',
                    DB::raw('COUNT(*) as payout_runs_count'),
                    DB::raw('COALESCE(SUM(p.total_cents), 0) as total_cents'),
                ])
                ->groupBy('p.status', 'p.currency_code')
                ->get();

            $brandPayoutInserts = $payoutRows->map(fn ($row): array => [
                'day' => $day,
                'brand_professional_id' => $brandProfessionalId,
                'payout_status' => (string) $row->payout_status,
                'currency_code' => (string) $row->currency_code,
                'timezone' => $timezone,
                'payout_runs_count' => (int) ($row->payout_runs_count ?? 0),
                'total_cents' => (int) ($row->total_cents ?? 0),
                'updated_at' => $now,
            ])->values()->all();

            if ($brandPayoutInserts !== []) {
                DB::table('analytics.brand_payout_daily')->insert($brandPayoutInserts);
            }

            $regionRows = DB::table('retail.orders as o')
                ->where('o.brand_professional_id', $brandProfessionalId)
                ->whereRaw('(o.ordered_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
                ->select([
                    'o.currency_code',
                    DB::raw("COALESCE(NULLIF(o.customer_region, ''), NULLIF(o.shipping_country_code, ''), 'unknown') as region"),
                    DB::raw('COUNT(*) as orders_count'),
                    DB::raw('COALESCE(SUM(o.net_cents), 0) as net_cents'),
                ])
                ->groupBy('o.currency_code', DB::raw("COALESCE(NULLIF(o.customer_region, ''), NULLIF(o.shipping_country_code, ''), 'unknown')"))
                ->get();

            $brandRegionInserts = $regionRows->map(fn ($row): array => [
                'day' => $day,
                'brand_professional_id' => $brandProfessionalId,
                'region' => (string) $row->region,
                'currency_code' => (string) $row->currency_code,
                'timezone' => $timezone,
                'orders_count' => (int) ($row->orders_count ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'updated_at' => $now,
            ])->values()->all();

            if ($brandRegionInserts !== []) {
                DB::table('analytics.brand_region_daily')->insert($brandRegionInserts);
            }

            $ordersByCurrency = $brandMetricsRows->keyBy(static fn ($row): string => (string) $row->currency_code);
            $dayCustomerRows = DB::table('retail.orders as o')
                ->where('o.brand_professional_id', $brandProfessionalId)
                ->whereRaw('(o.ordered_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
                ->whereNotNull('o.customer_email_hash')
                ->whereRaw("o.customer_email_hash <> ''")
                ->select('o.currency_code', 'o.customer_email_hash')
                ->distinct()
                ->get();

            $customerByCurrency = [];
            $allDayHashes = $dayCustomerRows
                ->pluck('customer_email_hash')
                ->map(static fn ($value): string => trim((string) $value))
                ->filter(static fn (string $value): bool => $value !== '')
                ->unique()
                ->values()
                ->all();

            $priorHashes = [];
            if ($allDayHashes !== []) {
                $priorHashes = DB::table('retail.orders as o')
                    ->where('o.brand_professional_id', $brandProfessionalId)
                    ->whereIn('o.customer_email_hash', $allDayHashes)
                    ->whereRaw('(o.ordered_at AT TIME ZONE ?)::date < ?', [$timezone, $day])
                    ->pluck('o.customer_email_hash')
                    ->map(static fn ($value): string => trim((string) $value))
                    ->filter(static fn (string $value): bool => $value !== '')
                    ->unique()
                    ->values()
                    ->all();
            }

            $priorLookup = array_fill_keys($priorHashes, true);
            foreach ($dayCustomerRows as $row) {
                $currency = (string) $row->currency_code;
                $hash = trim((string) $row->customer_email_hash);
                if ($currency === '' || $hash === '') {
                    continue;
                }

                $existing = $customerByCurrency[$currency] ?? [
                    'customers' => [],
                    'new' => [],
                    'returning' => [],
                ];

                $existing['customers'][$hash] = true;
                if (isset($priorLookup[$hash])) {
                    $existing['returning'][$hash] = true;
                } else {
                    $existing['new'][$hash] = true;
                }

                $customerByCurrency[$currency] = $existing;
            }

            $brandCustomerInserts = [];
            foreach ($ordersByCurrency as $currency => $metrics) {
                $customerStats = $customerByCurrency[(string) $currency] ?? [
                    'customers' => [],
                    'new' => [],
                    'returning' => [],
                ];

                $brandCustomerInserts[] = [
                    'day' => $day,
                    'brand_professional_id' => $brandProfessionalId,
                    'currency_code' => (string) $currency,
                    'timezone' => $timezone,
                    'customers_count' => count($customerStats['customers']),
                    'new_customers_count' => count($customerStats['new']),
                    'returning_customers_count' => count($customerStats['returning']),
                    'orders_count' => (int) ($metrics->orders_count ?? 0),
                    'net_cents' => (int) ($metrics->net_cents ?? 0),
                    'updated_at' => $now,
                ];
            }

            if ($brandCustomerInserts !== []) {
                DB::table('analytics.brand_customer_daily')->insert($brandCustomerInserts);
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
        $now = now();

        DB::transaction(function () use ($affiliateProfessionalId, $day, $timezone, $now): void {
            DB::table('analytics.professional_metrics_daily')
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('day', $day)
                ->delete();

            DB::table('analytics.professional_product_daily')
                ->where('affiliate_professional_id', $affiliateProfessionalId)
                ->where('day', $day)
                ->delete();

            $metricsRows = DB::table('retail.orders as o')
                ->where('o.affiliate_professional_id', $affiliateProfessionalId)
                ->whereRaw('(o.ordered_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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
                ->whereRaw('(l.occurred_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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
                ->whereRaw('(o.ordered_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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
                ->whereRaw('(l.occurred_at AT TIME ZONE ?)::date = ?', [$timezone, $day])
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

        DB::table('analytics.brand_payout_daily')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('day', $day)
            ->delete();

        DB::table('analytics.brand_region_daily')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('day', $day)
            ->delete();

        DB::table('analytics.brand_customer_daily')
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
