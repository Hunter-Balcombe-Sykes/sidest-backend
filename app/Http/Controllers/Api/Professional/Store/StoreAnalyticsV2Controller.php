<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Store\BrandAccessService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StoreAnalyticsV2Controller extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandAccessService $brandAccess,
    ) {}

    public function brandOverview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $brandIds = $this->resolveBrandScope(
            $professional,
            $filters['brand_professional_id'],
            BrandAccessService::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ
        );

        $base = DB::table('analytics.brand_metrics_daily as d')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        $totals = (clone $base)
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.gross_cents), 0) as gross_cents')
            ->selectRaw('COALESCE(SUM(d.refunded_cents), 0) as refunded_cents')
            ->selectRaw('COALESCE(SUM(d.returned_cents), 0) as returned_cents')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->first();

        $bucketExpr = $this->bucketExpression($filters['group_by'], 'd.day');
        $timeseries = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.gross_cents), 0) as gross_cents')
            ->selectRaw('COALESCE(SUM(d.refunded_cents), 0) as refunded_cents')
            ->selectRaw('COALESCE(SUM(d.returned_cents), 0) as returned_cents')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->groupByRaw($bucketExpr)
            ->orderBy('bucket')
            ->get();

        return $this->success([
            'range' => $this->rangePayload($filters),
            'group_by' => $filters['group_by'],
            'brand_professional_ids' => $brandIds,
            'totals' => [
                'orders_count' => (int) ($totals->orders_count ?? 0),
                'gross_cents' => (int) ($totals->gross_cents ?? 0),
                'refunded_cents' => (int) ($totals->refunded_cents ?? 0),
                'returned_cents' => (int) ($totals->returned_cents ?? 0),
                'net_cents' => (int) ($totals->net_cents ?? 0),
            ],
            'timeseries' => $timeseries->map(static fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'orders_count' => (int) ($row->orders_count ?? 0),
                'gross_cents' => (int) ($row->gross_cents ?? 0),
                'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                'returned_cents' => (int) ($row->returned_cents ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
            ])->values()->all(),
        ]);
    }

    public function brandInfluencers(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $brandIds = $this->resolveBrandScope(
            $professional,
            $filters['brand_professional_id'],
            BrandAccessService::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ
        );

        $query = DB::table('analytics.brand_influencer_daily as d')
            ->leftJoin('core.professionals as p', 'p.id', '=', 'd.affiliate_professional_id')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->whereBetween('d.day', [$filters['from'], $filters['to']])
            ->select([
                'd.affiliate_professional_id',
                'd.currency_code',
                'p.display_name as affiliate_display_name',
                'p.handle as affiliate_handle',
            ])
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.gross_cents), 0) as gross_cents')
            ->selectRaw('COALESCE(SUM(d.refunded_cents), 0) as refunded_cents')
            ->selectRaw('COALESCE(SUM(d.returned_cents), 0) as returned_cents')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_accrued_cents), 0) as commission_accrued_cents')
            ->selectRaw('COALESCE(SUM(d.commission_reversed_cents), 0) as commission_reversed_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->groupBy('d.affiliate_professional_id', 'd.currency_code', 'p.display_name', 'p.handle');

        $this->applySort(
            $query,
            $filters,
            [
                'orders_count' => 'orders_count',
                'gross_cents' => 'gross_cents',
                'net_cents' => 'net_cents',
                'commission_net_cents' => 'commission_net_cents',
                'affiliate_name' => 'affiliate_display_name',
            ],
            'net_cents'
        );

        [$rows, $total] = $this->paginate($query, $filters['page'], $filters['per_page']);

        return $this->success([
            'range' => $this->rangePayload($filters),
            'brand_professional_ids' => $brandIds,
            'data' => $rows->map(static fn ($row): array => [
                'affiliate_professional_id' => (string) $row->affiliate_professional_id,
                'affiliate_name' => $row->affiliate_display_name ?: $row->affiliate_handle ?: 'Affiliate',
                'currency_code' => (string) $row->currency_code,
                'orders_count' => (int) ($row->orders_count ?? 0),
                'gross_cents' => (int) ($row->gross_cents ?? 0),
                'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                'returned_cents' => (int) ($row->returned_cents ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_accrued_cents' => (int) ($row->commission_accrued_cents ?? 0),
                'commission_reversed_cents' => (int) ($row->commission_reversed_cents ?? 0),
                'commission_net_cents' => (int) ($row->commission_net_cents ?? 0),
            ])->values()->all(),
            'meta' => $this->metaPayload($filters['page'], $filters['per_page'], $total),
        ]);
    }

    public function brandInfluencerDetail(Request $request, string $professionalId): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $brandIds = $this->resolveBrandScope(
            $professional,
            $filters['brand_professional_id'],
            BrandAccessService::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ
        );

        $professionalId = trim($professionalId);

        $base = DB::table('analytics.brand_influencer_daily as d')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->where('d.affiliate_professional_id', $professionalId)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        $totals = (clone $base)
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.gross_cents), 0) as gross_cents')
            ->selectRaw('COALESCE(SUM(d.refunded_cents), 0) as refunded_cents')
            ->selectRaw('COALESCE(SUM(d.returned_cents), 0) as returned_cents')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_accrued_cents), 0) as commission_accrued_cents')
            ->selectRaw('COALESCE(SUM(d.commission_reversed_cents), 0) as commission_reversed_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->first();

        $bucketExpr = $this->bucketExpression($filters['group_by'], 'd.day');
        $timeseries = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->groupByRaw($bucketExpr)
            ->orderBy('bucket')
            ->get();

        $products = DB::table('analytics.brand_influencer_product_daily as d')
            ->leftJoin('retail.brand_products as bp', 'bp.id', '=', 'd.brand_product_id')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->where('d.affiliate_professional_id', $professionalId)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        $this->applyProductFilters($products, $filters, 'd');

        $products = $products
            ->select([
                'd.brand_product_id',
                'd.currency_code',
                'bp.title as product_title',
                'bp.shopify_product_id',
            ])
            ->selectRaw('COALESCE(SUM(d.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->groupBy('d.brand_product_id', 'd.currency_code', 'bp.title', 'bp.shopify_product_id')
            ->orderByDesc('net_cents')
            ->limit(100)
            ->get();

        return $this->success([
            'range' => $this->rangePayload($filters),
            'brand_professional_ids' => $brandIds,
            'affiliate_professional_id' => $professionalId,
            'totals' => [
                'orders_count' => (int) ($totals->orders_count ?? 0),
                'gross_cents' => (int) ($totals->gross_cents ?? 0),
                'refunded_cents' => (int) ($totals->refunded_cents ?? 0),
                'returned_cents' => (int) ($totals->returned_cents ?? 0),
                'net_cents' => (int) ($totals->net_cents ?? 0),
                'commission_accrued_cents' => (int) ($totals->commission_accrued_cents ?? 0),
                'commission_reversed_cents' => (int) ($totals->commission_reversed_cents ?? 0),
                'commission_net_cents' => (int) ($totals->commission_net_cents ?? 0),
            ],
            'timeseries' => $timeseries->map(static fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'orders_count' => (int) ($row->orders_count ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_net_cents' => (int) ($row->commission_net_cents ?? 0),
            ])->values()->all(),
            'products' => $products->map(static fn ($row): array => [
                'brand_product_id' => (string) $row->brand_product_id,
                'title' => $row->product_title ?: 'Product',
                'shopify_product_id' => $row->shopify_product_id,
                'currency_code' => (string) $row->currency_code,
                'units_sold' => (int) ($row->units_sold ?? 0),
                'orders_count' => (int) ($row->orders_count ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_net_cents' => (int) ($row->commission_net_cents ?? 0),
            ])->values()->all(),
        ]);
    }

    public function brandProducts(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $brandIds = $this->resolveBrandScope(
            $professional,
            $filters['brand_professional_id'],
            BrandAccessService::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ
        );

        $query = DB::table('analytics.brand_product_daily as d')
            ->leftJoin('retail.brand_products as bp', 'bp.id', '=', 'd.brand_product_id')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        $this->applyProductFilters($query, $filters, 'd');

        $query = $query
            ->select([
                'd.brand_product_id',
                'd.currency_code',
                'bp.title as product_title',
                'bp.shopify_product_id',
            ])
            ->selectRaw('COALESCE(SUM(d.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.gross_cents), 0) as gross_cents')
            ->selectRaw('COALESCE(SUM(d.refunded_cents), 0) as refunded_cents')
            ->selectRaw('COALESCE(SUM(d.returned_cents), 0) as returned_cents')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->groupBy('d.brand_product_id', 'd.currency_code', 'bp.title', 'bp.shopify_product_id');

        $this->applySort(
            $query,
            $filters,
            [
                'units_sold' => 'units_sold',
                'orders_count' => 'orders_count',
                'gross_cents' => 'gross_cents',
                'net_cents' => 'net_cents',
                'commission_net_cents' => 'commission_net_cents',
                'title' => 'product_title',
            ],
            'net_cents'
        );

        [$rows, $total] = $this->paginate($query, $filters['page'], $filters['per_page']);

        return $this->success([
            'range' => $this->rangePayload($filters),
            'brand_professional_ids' => $brandIds,
            'data' => $rows->map(static fn ($row): array => [
                'brand_product_id' => (string) $row->brand_product_id,
                'title' => $row->product_title ?: 'Product',
                'shopify_product_id' => $row->shopify_product_id,
                'currency_code' => (string) $row->currency_code,
                'units_sold' => (int) ($row->units_sold ?? 0),
                'orders_count' => (int) ($row->orders_count ?? 0),
                'gross_cents' => (int) ($row->gross_cents ?? 0),
                'refunded_cents' => (int) ($row->refunded_cents ?? 0),
                'returned_cents' => (int) ($row->returned_cents ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_net_cents' => (int) ($row->commission_net_cents ?? 0),
            ])->values()->all(),
            'meta' => $this->metaPayload($filters['page'], $filters['per_page'], $total),
        ]);
    }

    public function brandProductDetail(Request $request, string $brandProductId): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $brandIds = $this->resolveBrandScope(
            $professional,
            $filters['brand_professional_id'],
            BrandAccessService::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ
        );

        $brandProductId = trim($brandProductId);

        $base = DB::table('analytics.brand_product_daily as d')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->where('d.brand_product_id', $brandProductId)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        $totals = (clone $base)
            ->selectRaw('COALESCE(SUM(d.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.gross_cents), 0) as gross_cents')
            ->selectRaw('COALESCE(SUM(d.refunded_cents), 0) as refunded_cents')
            ->selectRaw('COALESCE(SUM(d.returned_cents), 0) as returned_cents')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->first();

        if ((int) ($totals->orders_count ?? 0) === 0 && (int) ($totals->units_sold ?? 0) === 0) {
            return $this->error('Brand product analytics not found for selected scope/range.', 404);
        }

        $bucketExpr = $this->bucketExpression($filters['group_by'], 'd.day');
        $timeseries = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw('COALESCE(SUM(d.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->groupByRaw($bucketExpr)
            ->orderBy('bucket')
            ->get();

        $influencers = DB::table('analytics.brand_influencer_product_daily as d')
            ->leftJoin('core.professionals as p', 'p.id', '=', 'd.affiliate_professional_id')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->where('d.brand_product_id', $brandProductId)
            ->whereBetween('d.day', [$filters['from'], $filters['to']])
            ->select([
                'd.affiliate_professional_id',
                'd.currency_code',
                'p.display_name as affiliate_display_name',
                'p.handle as affiliate_handle',
            ])
            ->selectRaw('COALESCE(SUM(d.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->groupBy('d.affiliate_professional_id', 'd.currency_code', 'p.display_name', 'p.handle')
            ->orderByDesc('net_cents')
            ->limit(100)
            ->get();

        return $this->success([
            'range' => $this->rangePayload($filters),
            'brand_professional_ids' => $brandIds,
            'brand_product_id' => $brandProductId,
            'totals' => [
                'units_sold' => (int) ($totals->units_sold ?? 0),
                'orders_count' => (int) ($totals->orders_count ?? 0),
                'gross_cents' => (int) ($totals->gross_cents ?? 0),
                'refunded_cents' => (int) ($totals->refunded_cents ?? 0),
                'returned_cents' => (int) ($totals->returned_cents ?? 0),
                'net_cents' => (int) ($totals->net_cents ?? 0),
                'commission_net_cents' => (int) ($totals->commission_net_cents ?? 0),
            ],
            'timeseries' => $timeseries->map(static fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'units_sold' => (int) ($row->units_sold ?? 0),
                'orders_count' => (int) ($row->orders_count ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_net_cents' => (int) ($row->commission_net_cents ?? 0),
            ])->values()->all(),
            'influencers' => $influencers->map(static fn ($row): array => [
                'affiliate_professional_id' => (string) $row->affiliate_professional_id,
                'affiliate_name' => $row->affiliate_display_name ?: $row->affiliate_handle ?: 'Affiliate',
                'currency_code' => (string) $row->currency_code,
                'units_sold' => (int) ($row->units_sold ?? 0),
                'orders_count' => (int) ($row->orders_count ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_net_cents' => (int) ($row->commission_net_cents ?? 0),
            ])->values()->all(),
        ]);
    }

    public function brandCommissions(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $brandIds = $this->resolveBrandScope(
            $professional,
            $filters['brand_professional_id'],
            BrandAccessService::CAPABILITY_ANALYTICS_FINANCIAL_READ
        );

        $query = DB::table('analytics.brand_commission_daily as d')
            ->leftJoin('core.professionals as p', 'p.id', '=', 'd.affiliate_professional_id')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        if ($filters['payout_status'] !== []) {
            $query->whereIn('d.payout_status', $filters['payout_status']);
        }

        $query = $query
            ->select([
                'd.affiliate_professional_id',
                'd.payout_status',
                'd.currency_code',
                'p.display_name as affiliate_display_name',
                'p.handle as affiliate_handle',
            ])
            ->selectRaw('COALESCE(SUM(d.accrual_cents), 0) as accrual_cents')
            ->selectRaw('COALESCE(SUM(d.reversal_cents), 0) as reversal_cents')
            ->selectRaw('COALESCE(SUM(d.payout_cents), 0) as payout_cents')
            ->selectRaw('COALESCE(SUM(d.net_outstanding_cents), 0) as net_outstanding_cents')
            ->selectRaw('COALESCE(SUM(d.entries_count), 0) as entries_count')
            ->groupBy('d.affiliate_professional_id', 'd.payout_status', 'd.currency_code', 'p.display_name', 'p.handle');

        $this->applySort(
            $query,
            $filters,
            [
                'accrual_cents' => 'accrual_cents',
                'reversal_cents' => 'reversal_cents',
                'payout_cents' => 'payout_cents',
                'net_outstanding_cents' => 'net_outstanding_cents',
                'entries_count' => 'entries_count',
                'affiliate_name' => 'affiliate_display_name',
            ],
            'net_outstanding_cents'
        );

        [$rows, $total] = $this->paginate($query, $filters['page'], $filters['per_page']);

        return $this->success([
            'range' => $this->rangePayload($filters),
            'brand_professional_ids' => $brandIds,
            'data' => $rows->map(static fn ($row): array => [
                'affiliate_professional_id' => (string) $row->affiliate_professional_id,
                'affiliate_name' => $row->affiliate_display_name ?: $row->affiliate_handle ?: 'Affiliate',
                'payout_status' => (string) $row->payout_status,
                'currency_code' => (string) $row->currency_code,
                'accrual_cents' => (int) ($row->accrual_cents ?? 0),
                'reversal_cents' => (int) ($row->reversal_cents ?? 0),
                'payout_cents' => (int) ($row->payout_cents ?? 0),
                'net_outstanding_cents' => (int) ($row->net_outstanding_cents ?? 0),
                'entries_count' => (int) ($row->entries_count ?? 0),
            ])->values()->all(),
            'meta' => $this->metaPayload($filters['page'], $filters['per_page'], $total),
        ]);
    }

    public function brandPayouts(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $brandIds = $this->resolveBrandScope(
            $professional,
            $filters['brand_professional_id'],
            BrandAccessService::CAPABILITY_ANALYTICS_FINANCIAL_READ
        );

        $query = DB::table('analytics.brand_payout_daily as d')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        if ($filters['payout_status'] !== []) {
            $query->whereIn('d.payout_status', $filters['payout_status']);
        }

        $query = $query
            ->select(['d.payout_status', 'd.currency_code'])
            ->selectRaw('COALESCE(SUM(d.payout_runs_count), 0) as payout_runs_count')
            ->selectRaw('COALESCE(SUM(d.total_cents), 0) as total_cents')
            ->groupBy('d.payout_status', 'd.currency_code');

        $this->applySort(
            $query,
            $filters,
            [
                'payout_runs_count' => 'payout_runs_count',
                'total_cents' => 'total_cents',
                'payout_status' => 'payout_status',
            ],
            'total_cents'
        );

        [$rows, $total] = $this->paginate($query, $filters['page'], $filters['per_page']);

        return $this->success([
            'range' => $this->rangePayload($filters),
            'brand_professional_ids' => $brandIds,
            'data' => $rows->map(static fn ($row): array => [
                'payout_status' => (string) $row->payout_status,
                'currency_code' => (string) $row->currency_code,
                'payout_runs_count' => (int) ($row->payout_runs_count ?? 0),
                'total_cents' => (int) ($row->total_cents ?? 0),
            ])->values()->all(),
            'meta' => $this->metaPayload($filters['page'], $filters['per_page'], $total),
        ]);
    }

    public function brandTimeseries(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $brandIds = $this->resolveBrandScope(
            $professional,
            $filters['brand_professional_id'],
            BrandAccessService::CAPABILITY_ANALYTICS_NON_FINANCIAL_READ
        );

        $bucketExpr = $this->bucketExpression($filters['group_by'], 'd.day');

        $timeseries = DB::table('analytics.brand_metrics_daily as d')
            ->whereIn('d.brand_professional_id', $brandIds)
            ->whereBetween('d.day', [$filters['from'], $filters['to']])
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.gross_cents), 0) as gross_cents')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->groupByRaw($bucketExpr)
            ->orderBy('bucket')
            ->get();

        return $this->success([
            'range' => $this->rangePayload($filters),
            'group_by' => $filters['group_by'],
            'brand_professional_ids' => $brandIds,
            'data' => $timeseries->map(static fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'orders_count' => (int) ($row->orders_count ?? 0),
                'gross_cents' => (int) ($row->gross_cents ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
            ])->values()->all(),
        ]);
    }

    public function myOverview(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);

        $base = DB::table('analytics.professional_metrics_daily as d')
            ->where('d.affiliate_professional_id', (string) $professional->id)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        $totals = (clone $base)
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.gross_cents), 0) as gross_cents')
            ->selectRaw('COALESCE(SUM(d.refunded_cents), 0) as refunded_cents')
            ->selectRaw('COALESCE(SUM(d.returned_cents), 0) as returned_cents')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_accrued_cents), 0) as commission_accrued_cents')
            ->selectRaw('COALESCE(SUM(d.commission_reversed_cents), 0) as commission_reversed_cents')
            ->selectRaw('COALESCE(SUM(d.commission_paid_cents), 0) as commission_paid_cents')
            ->first();

        $bucketExpr = $this->bucketExpression($filters['group_by'], 'd.day');
        $timeseries = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_accrued_cents), 0) as commission_accrued_cents')
            ->selectRaw('COALESCE(SUM(d.commission_reversed_cents), 0) as commission_reversed_cents')
            ->selectRaw('COALESCE(SUM(d.commission_paid_cents), 0) as commission_paid_cents')
            ->groupByRaw($bucketExpr)
            ->orderBy('bucket')
            ->get();

        return $this->success([
            'range' => $this->rangePayload($filters),
            'group_by' => $filters['group_by'],
            'affiliate_professional_id' => (string) $professional->id,
            'totals' => [
                'orders_count' => (int) ($totals->orders_count ?? 0),
                'gross_cents' => (int) ($totals->gross_cents ?? 0),
                'refunded_cents' => (int) ($totals->refunded_cents ?? 0),
                'returned_cents' => (int) ($totals->returned_cents ?? 0),
                'net_cents' => (int) ($totals->net_cents ?? 0),
                'commission_accrued_cents' => (int) ($totals->commission_accrued_cents ?? 0),
                'commission_reversed_cents' => (int) ($totals->commission_reversed_cents ?? 0),
                'commission_paid_cents' => (int) ($totals->commission_paid_cents ?? 0),
            ],
            'timeseries' => $timeseries->map(static fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'orders_count' => (int) ($row->orders_count ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_accrued_cents' => (int) ($row->commission_accrued_cents ?? 0),
                'commission_reversed_cents' => (int) ($row->commission_reversed_cents ?? 0),
                'commission_paid_cents' => (int) ($row->commission_paid_cents ?? 0),
            ])->values()->all(),
        ]);
    }

    public function myProducts(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);

        $query = DB::table('analytics.professional_product_daily as d')
            ->leftJoin('retail.brand_products as bp', 'bp.id', '=', 'd.brand_product_id')
            ->leftJoin('core.professionals as b', 'b.id', '=', 'd.brand_professional_id')
            ->where('d.affiliate_professional_id', (string) $professional->id)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        $this->applyProductFilters($query, $filters, 'd');

        $query = $query
            ->select([
                'd.brand_product_id',
                'd.brand_professional_id',
                'd.currency_code',
                'bp.title as product_title',
                'bp.shopify_product_id',
                'b.display_name as brand_display_name',
                'b.handle as brand_handle',
            ])
            ->selectRaw('COALESCE(SUM(d.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->groupBy(
                'd.brand_product_id',
                'd.brand_professional_id',
                'd.currency_code',
                'bp.title',
                'bp.shopify_product_id',
                'b.display_name',
                'b.handle'
            );

        $this->applySort(
            $query,
            $filters,
            [
                'units_sold' => 'units_sold',
                'orders_count' => 'orders_count',
                'net_cents' => 'net_cents',
                'commission_net_cents' => 'commission_net_cents',
                'title' => 'product_title',
            ],
            'net_cents'
        );

        [$rows, $total] = $this->paginate($query, $filters['page'], $filters['per_page']);

        return $this->success([
            'range' => $this->rangePayload($filters),
            'affiliate_professional_id' => (string) $professional->id,
            'data' => $rows->map(static fn ($row): array => [
                'brand_product_id' => (string) $row->brand_product_id,
                'brand_professional_id' => (string) $row->brand_professional_id,
                'brand_name' => $row->brand_display_name ?: $row->brand_handle ?: 'Brand',
                'title' => $row->product_title ?: 'Product',
                'shopify_product_id' => $row->shopify_product_id,
                'currency_code' => (string) $row->currency_code,
                'units_sold' => (int) ($row->units_sold ?? 0),
                'orders_count' => (int) ($row->orders_count ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_net_cents' => (int) ($row->commission_net_cents ?? 0),
            ])->values()->all(),
            'meta' => $this->metaPayload($filters['page'], $filters['per_page'], $total),
        ]);
    }

    public function myProductDetail(Request $request, string $brandProductId): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $brandProductId = trim($brandProductId);

        $base = DB::table('analytics.professional_product_daily as d')
            ->where('d.affiliate_professional_id', (string) $professional->id)
            ->where('d.brand_product_id', $brandProductId)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        $totals = (clone $base)
            ->selectRaw('COALESCE(SUM(d.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->first();

        if ((int) ($totals->orders_count ?? 0) === 0 && (int) ($totals->units_sold ?? 0) === 0) {
            return $this->error('Product analytics not found for selected scope/range.', 404);
        }

        $bucketExpr = $this->bucketExpression($filters['group_by'], 'd.day');
        $timeseries = (clone $base)
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw('COALESCE(SUM(d.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->groupByRaw($bucketExpr)
            ->orderBy('bucket')
            ->get();

        $brands = DB::table('analytics.professional_product_daily as d')
            ->leftJoin('core.professionals as b', 'b.id', '=', 'd.brand_professional_id')
            ->where('d.affiliate_professional_id', (string) $professional->id)
            ->where('d.brand_product_id', $brandProductId)
            ->whereBetween('d.day', [$filters['from'], $filters['to']])
            ->select([
                'd.brand_professional_id',
                'd.currency_code',
                'b.display_name as brand_display_name',
                'b.handle as brand_handle',
            ])
            ->selectRaw('COALESCE(SUM(d.units_sold), 0) as units_sold')
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_net_cents), 0) as commission_net_cents')
            ->groupBy('d.brand_professional_id', 'd.currency_code', 'b.display_name', 'b.handle')
            ->orderByDesc('net_cents')
            ->limit(50)
            ->get();

        return $this->success([
            'range' => $this->rangePayload($filters),
            'affiliate_professional_id' => (string) $professional->id,
            'brand_product_id' => $brandProductId,
            'totals' => [
                'units_sold' => (int) ($totals->units_sold ?? 0),
                'orders_count' => (int) ($totals->orders_count ?? 0),
                'net_cents' => (int) ($totals->net_cents ?? 0),
                'commission_net_cents' => (int) ($totals->commission_net_cents ?? 0),
            ],
            'timeseries' => $timeseries->map(static fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'units_sold' => (int) ($row->units_sold ?? 0),
                'orders_count' => (int) ($row->orders_count ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_net_cents' => (int) ($row->commission_net_cents ?? 0),
            ])->values()->all(),
            'brands' => $brands->map(static fn ($row): array => [
                'brand_professional_id' => (string) $row->brand_professional_id,
                'brand_name' => $row->brand_display_name ?: $row->brand_handle ?: 'Brand',
                'currency_code' => (string) $row->currency_code,
                'units_sold' => (int) ($row->units_sold ?? 0),
                'orders_count' => (int) ($row->orders_count ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_net_cents' => (int) ($row->commission_net_cents ?? 0),
            ])->values()->all(),
        ]);
    }

    public function myCommissions(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);

        $query = DB::table('analytics.brand_commission_daily as d')
            ->leftJoin('core.professionals as b', 'b.id', '=', 'd.brand_professional_id')
            ->where('d.affiliate_professional_id', (string) $professional->id)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        if ($filters['payout_status'] !== []) {
            $query->whereIn('d.payout_status', $filters['payout_status']);
        }

        $query = $query
            ->select([
                'd.brand_professional_id',
                'd.payout_status',
                'd.currency_code',
                'b.display_name as brand_display_name',
                'b.handle as brand_handle',
            ])
            ->selectRaw('COALESCE(SUM(d.accrual_cents), 0) as accrual_cents')
            ->selectRaw('COALESCE(SUM(d.reversal_cents), 0) as reversal_cents')
            ->selectRaw('COALESCE(SUM(d.payout_cents), 0) as payout_cents')
            ->selectRaw('COALESCE(SUM(d.net_outstanding_cents), 0) as net_outstanding_cents')
            ->selectRaw('COALESCE(SUM(d.entries_count), 0) as entries_count')
            ->groupBy('d.brand_professional_id', 'd.payout_status', 'd.currency_code', 'b.display_name', 'b.handle');

        $this->applySort(
            $query,
            $filters,
            [
                'accrual_cents' => 'accrual_cents',
                'reversal_cents' => 'reversal_cents',
                'payout_cents' => 'payout_cents',
                'net_outstanding_cents' => 'net_outstanding_cents',
                'entries_count' => 'entries_count',
                'brand_name' => 'brand_display_name',
            ],
            'net_outstanding_cents'
        );

        [$rows, $total] = $this->paginate($query, $filters['page'], $filters['per_page']);

        return $this->success([
            'range' => $this->rangePayload($filters),
            'affiliate_professional_id' => (string) $professional->id,
            'data' => $rows->map(static fn ($row): array => [
                'brand_professional_id' => (string) $row->brand_professional_id,
                'brand_name' => $row->brand_display_name ?: $row->brand_handle ?: 'Brand',
                'payout_status' => (string) $row->payout_status,
                'currency_code' => (string) $row->currency_code,
                'accrual_cents' => (int) ($row->accrual_cents ?? 0),
                'reversal_cents' => (int) ($row->reversal_cents ?? 0),
                'payout_cents' => (int) ($row->payout_cents ?? 0),
                'net_outstanding_cents' => (int) ($row->net_outstanding_cents ?? 0),
                'entries_count' => (int) ($row->entries_count ?? 0),
            ])->values()->all(),
            'meta' => $this->metaPayload($filters['page'], $filters['per_page'], $total),
        ]);
    }

    public function myPayouts(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);

        $query = DB::table('analytics.brand_commission_daily as d')
            ->where('d.affiliate_professional_id', (string) $professional->id)
            ->whereBetween('d.day', [$filters['from'], $filters['to']]);

        if ($filters['payout_status'] !== []) {
            $query->whereIn('d.payout_status', $filters['payout_status']);
        }

        $query = $query
            ->select(['d.payout_status', 'd.currency_code'])
            ->selectRaw('COALESCE(SUM(d.payout_cents), 0) as payout_cents')
            ->selectRaw('COALESCE(SUM(d.net_outstanding_cents), 0) as net_outstanding_cents')
            ->groupBy('d.payout_status', 'd.currency_code');

        $this->applySort(
            $query,
            $filters,
            [
                'payout_cents' => 'payout_cents',
                'net_outstanding_cents' => 'net_outstanding_cents',
                'payout_status' => 'payout_status',
            ],
            'payout_cents'
        );

        [$rows, $total] = $this->paginate($query, $filters['page'], $filters['per_page']);

        return $this->success([
            'range' => $this->rangePayload($filters),
            'affiliate_professional_id' => (string) $professional->id,
            'data' => $rows->map(static fn ($row): array => [
                'payout_status' => (string) $row->payout_status,
                'currency_code' => (string) $row->currency_code,
                'payout_cents' => (int) ($row->payout_cents ?? 0),
                'net_outstanding_cents' => (int) ($row->net_outstanding_cents ?? 0),
            ])->values()->all(),
            'meta' => $this->metaPayload($filters['page'], $filters['per_page'], $total),
        ]);
    }

    public function myTimeseries(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $filters = $this->resolveFilters($request);
        $bucketExpr = $this->bucketExpression($filters['group_by'], 'd.day');

        $data = DB::table('analytics.professional_metrics_daily as d')
            ->where('d.affiliate_professional_id', (string) $professional->id)
            ->whereBetween('d.day', [$filters['from'], $filters['to']])
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw('COALESCE(SUM(d.orders_count), 0) as orders_count')
            ->selectRaw('COALESCE(SUM(d.gross_cents), 0) as gross_cents')
            ->selectRaw('COALESCE(SUM(d.net_cents), 0) as net_cents')
            ->selectRaw('COALESCE(SUM(d.commission_accrued_cents), 0) as commission_accrued_cents')
            ->selectRaw('COALESCE(SUM(d.commission_reversed_cents), 0) as commission_reversed_cents')
            ->selectRaw('COALESCE(SUM(d.commission_paid_cents), 0) as commission_paid_cents')
            ->groupByRaw($bucketExpr)
            ->orderBy('bucket')
            ->get();

        return $this->success([
            'range' => $this->rangePayload($filters),
            'group_by' => $filters['group_by'],
            'affiliate_professional_id' => (string) $professional->id,
            'data' => $data->map(static fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'orders_count' => (int) ($row->orders_count ?? 0),
                'gross_cents' => (int) ($row->gross_cents ?? 0),
                'net_cents' => (int) ($row->net_cents ?? 0),
                'commission_accrued_cents' => (int) ($row->commission_accrued_cents ?? 0),
                'commission_reversed_cents' => (int) ($row->commission_reversed_cents ?? 0),
                'commission_paid_cents' => (int) ($row->commission_paid_cents ?? 0),
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'group_by' => ['sometimes', 'in:day,week,month'],
            'brand_professional_id' => ['sometimes', 'uuid'],
            'product_ids' => ['sometimes', 'array'],
            'product_ids.*' => ['uuid'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['string', 'max:120'],
            'collections' => ['sometimes', 'array'],
            'collections.*' => ['string', 'max:120'],
            'regions' => ['sometimes', 'array'],
            'regions.*' => ['string', 'max:120'],
            'lifecycle_status' => ['sometimes', 'array'],
            'lifecycle_status.*' => ['string', 'max:60'],
            'financial_status' => ['sometimes', 'array'],
            'financial_status.*' => ['string', 'max:60'],
            'payout_status' => ['sometimes', 'array'],
            'payout_status.*' => ['string', 'max:60'],
            'sort_by' => ['sometimes', 'string', 'max:80'],
            'sort_dir' => ['sometimes', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        $from = isset($validated['from'])
            ? Carbon::createFromFormat('Y-m-d', (string) $validated['from'])
            : now()->subDays(30)->startOfDay();

        $to = isset($validated['to'])
            ? Carbon::createFromFormat('Y-m-d', (string) $validated['to'])
            : now()->endOfDay();

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
            'brand_professional_id' => isset($validated['brand_professional_id'])
                ? (string) $validated['brand_professional_id']
                : null,
            'product_ids' => array_values(array_unique(array_map('strval', $validated['product_ids'] ?? []))),
            'categories' => array_values(array_unique(array_map(static fn ($value): string => mb_strtolower(trim((string) $value)), $validated['categories'] ?? []))),
            'collections' => array_values(array_unique(array_map(static fn ($value): string => mb_strtolower(trim((string) $value)), $validated['collections'] ?? []))),
            'regions' => array_values(array_unique(array_map(static fn ($value): string => mb_strtolower(trim((string) $value)), $validated['regions'] ?? []))),
            'lifecycle_status' => array_values(array_unique(array_map(static fn ($value): string => mb_strtolower(trim((string) $value)), $validated['lifecycle_status'] ?? []))),
            'financial_status' => array_values(array_unique(array_map(static fn ($value): string => mb_strtolower(trim((string) $value)), $validated['financial_status'] ?? []))),
            'payout_status' => array_values(array_unique(array_map(static fn ($value): string => mb_strtolower(trim((string) $value)), $validated['payout_status'] ?? []))),
            'sort_by' => trim((string) ($validated['sort_by'] ?? '')),
            'sort_dir' => mb_strtolower(trim((string) ($validated['sort_dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) ($validated['page'] ?? 1)),
            'per_page' => max(1, min(100, (int) ($validated['per_page'] ?? 25))),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveBrandScope(object $professional, ?string $requestedBrandId, string $capability): array
    {
        $managedBrandIds = $this->brandAccess->brandIdsForCapability($professional, $capability);

        if ($managedBrandIds === []) {
            abort(403, 'You are not permitted to view brand analytics.');
        }

        if ($requestedBrandId !== null && $requestedBrandId !== '') {
            if (! in_array($requestedBrandId, $managedBrandIds, true)) {
                abort(403, 'Requested brand is outside your analytics scope.');
            }

            return [$requestedBrandId];
        }

        return $managedBrandIds;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyProductFilters(Builder $query, array $filters, string $alias): void
    {
        if ($filters['product_ids'] !== []) {
            $query->whereIn("{$alias}.brand_product_id", $filters['product_ids']);
        }

        if ($filters['categories'] !== []) {
            $query->whereIn(DB::raw("lower(COALESCE({$alias}.category, ''))"), $filters['categories']);
        }

        if ($filters['collections'] !== []) {
            $query->whereIn(DB::raw("lower(COALESCE({$alias}.collection, ''))"), $filters['collections']);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $sortMap
     */
    private function applySort(Builder $query, array $filters, array $sortMap, string $defaultSort): void
    {
        $sortBy = $filters['sort_by'];
        $sortDir = $filters['sort_dir'];

        $column = $sortMap[$sortBy] ?? $sortMap[$defaultSort] ?? $defaultSort;

        $query->orderBy($column, $sortDir);
    }

    private function bucketExpression(string $groupBy, string $column): string
    {
        return match ($groupBy) {
            'week' => "date_trunc('week', {$column}::timestamp)::date",
            'month' => "date_trunc('month', {$column}::timestamp)::date",
            default => $column,
        };
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<int, object>, 1: int}
     */
    private function paginate(Builder $query, int $page, int $perPage): array
    {
        $countQuery = DB::query()->fromSub(clone $query, 'q');
        $total = (int) $countQuery->count();

        $rows = (clone $query)
            ->forPage($page, $perPage)
            ->get();

        return [$rows, $total];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{from: string, to: string}
     */
    private function rangePayload(array $filters): array
    {
        return [
            'from' => (string) $filters['from'],
            'to' => (string) $filters['to'],
        ];
    }

    /**
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    private function metaPayload(int $page, int $perPage, int $total): array
    {
        $lastPage = (int) max(1, (int) ceil($total / max(1, $perPage)));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ];
    }
}
