<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Store\BrandAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreAnalyticsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandAccessService $brandAccess
    ) {}

    /**
     * GET /store/analytics
     * Affiliate/user storefront order analytics.
     */
    public function index(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        try {
            [$from, $to] = $this->resolveRange($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            return $this->error('Invalid date range. Use YYYY-MM-DD for from/to.', 422);
        }

        if (! $this->analyticsStorageReady()) {
            return $this->success($this->emptyStoreAnalyticsPayload($from, $to));
        }

        try {
            $base = DB::table('analytics.store_order_events as e')
                ->where('e.professional_id', (string) $professional->id)
                ->whereBetween('e.occurred_at', [$from, $to]);

            $summary = (clone $base)
                ->selectRaw('COUNT(*) as total_orders')
                ->selectRaw('COALESCE(SUM(e.order_value_cents), 0) as total_revenue_cents')
                ->selectRaw('COALESCE(AVG(e.order_value_cents), 0) as average_order_value_cents')
                ->first();

            $ordersByDay = (clone $base)
                ->selectRaw('DATE(e.occurred_at) as day')
                ->selectRaw('COUNT(*) as orders')
                ->selectRaw('COALESCE(SUM(e.order_value_cents), 0) as revenue_cents')
                ->groupByRaw('DATE(e.occurred_at)')
                ->orderBy('day')
                ->get();

            $orders = (clone $base)
                ->orderByDesc('e.occurred_at')
                ->limit(100)
                ->get([
                    'e.id',
                    'e.occurred_at',
                    'e.customer_name',
                    'e.customer_email',
                    'e.customer_phone',
                    'e.order_name',
                    'e.payment_method',
                    'e.currency_code',
                    'e.order_value_cents',
                    'e.line_item_count',
                ]);

            return $this->success([
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'totals' => [
                    'orders' => (int) ($summary->total_orders ?? 0),
                    'revenue_cents' => (int) ($summary->total_revenue_cents ?? 0),
                    'average_order_value_cents' => (int) round((float) ($summary->average_order_value_cents ?? 0)),
                ],
                'charts' => [
                    'orders_by_day' => $ordersByDay
                        ->map(static fn ($row): array => [
                            'day' => (string) ($row->day ?? ''),
                            'orders' => (int) ($row->orders ?? 0),
                            'revenue_cents' => (int) ($row->revenue_cents ?? 0),
                        ])
                        ->values()
                        ->all(),
                ],
                'orders' => $orders
                    ->map(static fn ($row): array => [
                        'id' => (string) ($row->id ?? ''),
                        'occurred_at' => isset($row->occurred_at) ? (string) $row->occurred_at : null,
                        'customer_name' => $row->customer_name,
                        'customer_email' => $row->customer_email,
                        'customer_phone' => $row->customer_phone,
                        'order_name' => $row->order_name,
                        'payment_method' => $row->payment_method,
                        'currency_code' => $row->currency_code ?: 'AUD',
                        'order_value_cents' => (int) ($row->order_value_cents ?? 0),
                        'line_item_count' => (int) ($row->line_item_count ?? 0),
                    ])
                    ->values()
                    ->all(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->success($this->emptyStoreAnalyticsPayload($from, $to));
        }
    }

    /**
     * GET /store/brand-analytics
     * Brand rollup across all connected brand-partner accounts.
     */
    public function brandIndex(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $managedBrandIds = $this->brandAccess->managedBrandIds($professional);

        if ($managedBrandIds === []) {
            return $this->error('You are not permitted to view brand analytics.', 403);
        }

        try {
            [$from, $to] = $this->resolveRange($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            return $this->error('Invalid date range. Use YYYY-MM-DD for from/to.', 422);
        }

        if (! $this->analyticsStorageReady()) {
            return $this->success($this->emptyBrandAnalyticsPayload($managedBrandIds, $from, $to));
        }

        try {
            $base = DB::table('analytics.store_order_event_items as i')
                ->join('analytics.store_order_events as e', 'e.id', '=', 'i.event_id')
                ->whereIn('i.brand_professional_id', $managedBrandIds)
                ->whereBetween('e.occurred_at', [$from, $to]);

            $summary = (clone $base)
                ->selectRaw('COUNT(DISTINCT e.id) as total_orders')
                ->selectRaw('COALESCE(SUM(COALESCE(i.line_total_cents, 0)), 0) as total_revenue_cents')
                ->first();

            $ordersByDay = (clone $base)
                ->selectRaw('DATE(e.occurred_at) as day')
                ->selectRaw('COUNT(DISTINCT e.id) as orders')
                ->selectRaw('COALESCE(SUM(COALESCE(i.line_total_cents, 0)), 0) as revenue_cents')
                ->groupByRaw('DATE(e.occurred_at)')
                ->orderBy('day')
                ->get();

            $orders = (clone $base)
                ->leftJoin('core.professionals as ap', 'ap.id', '=', 'e.professional_id')
                ->select([
                    'e.id as event_id',
                    'e.occurred_at',
                    'e.customer_name',
                    'e.customer_email',
                    'e.order_name',
                    'e.payment_method',
                    'e.currency_code',
                    'e.professional_id as partner_professional_id',
                    'ap.display_name as partner_display_name',
                    'ap.handle as partner_handle',
                ])
                ->selectRaw('COUNT(i.id) as line_item_rows')
                ->selectRaw('COALESCE(SUM(COALESCE(i.line_total_cents, 0)), 0) as revenue_cents')
                ->groupBy(
                    'e.id',
                    'e.occurred_at',
                    'e.customer_name',
                    'e.customer_email',
                    'e.order_name',
                    'e.payment_method',
                    'e.currency_code',
                    'e.professional_id',
                    'ap.display_name',
                    'ap.handle'
                )
                ->orderByDesc('e.occurred_at')
                ->limit(100)
                ->get();

            $brandBreakdown = (clone $base)
                ->leftJoin('core.professionals as bp', 'bp.id', '=', 'i.brand_professional_id')
                ->select([
                    'i.brand_professional_id',
                    'bp.display_name as brand_display_name',
                    'bp.handle as brand_handle',
                ])
                ->selectRaw('COUNT(DISTINCT e.id) as orders')
                ->selectRaw('COALESCE(SUM(COALESCE(i.line_total_cents, 0)), 0) as revenue_cents')
                ->groupBy('i.brand_professional_id', 'bp.display_name', 'bp.handle')
                ->orderByDesc('revenue_cents')
                ->get();

            return $this->success([
                'managed_brand_ids' => array_values($managedBrandIds),
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'totals' => [
                    'orders' => (int) ($summary->total_orders ?? 0),
                    'revenue_cents' => (int) ($summary->total_revenue_cents ?? 0),
                ],
                'charts' => [
                    'orders_by_day' => $ordersByDay
                        ->map(static fn ($row): array => [
                            'day' => (string) ($row->day ?? ''),
                            'orders' => (int) ($row->orders ?? 0),
                            'revenue_cents' => (int) ($row->revenue_cents ?? 0),
                        ])
                        ->values()
                        ->all(),
                ],
                'brands' => $brandBreakdown
                    ->map(static fn ($row): array => [
                        'brand_professional_id' => (string) ($row->brand_professional_id ?? ''),
                        'brand_name' => $row->brand_display_name ?: $row->brand_handle ?: 'Brand',
                        'orders' => (int) ($row->orders ?? 0),
                        'revenue_cents' => (int) ($row->revenue_cents ?? 0),
                    ])
                    ->values()
                    ->all(),
                'orders' => $orders
                    ->map(static fn ($row): array => [
                        'event_id' => (string) ($row->event_id ?? ''),
                        'occurred_at' => isset($row->occurred_at) ? (string) $row->occurred_at : null,
                        'customer_name' => $row->customer_name,
                        'customer_email' => $row->customer_email,
                        'order_name' => $row->order_name,
                        'payment_method' => $row->payment_method,
                        'currency_code' => $row->currency_code ?: 'AUD',
                        'partner_professional_id' => (string) ($row->partner_professional_id ?? ''),
                        'partner_name' => $row->partner_display_name ?: $row->partner_handle ?: 'Partner',
                        'line_item_rows' => (int) ($row->line_item_rows ?? 0),
                        'revenue_cents' => (int) ($row->revenue_cents ?? 0),
                    ])
                    ->values()
                    ->all(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->success($this->emptyBrandAnalyticsPayload($managedBrandIds, $from, $to));
        }
    }

    private function analyticsStorageReady(): bool
    {
        try {
            $eventsRegclass = DB::selectOne("SELECT to_regclass('analytics.store_order_events') AS name");
            $itemsRegclass = DB::selectOne("SELECT to_regclass('analytics.store_order_event_items') AS name");

            return ($eventsRegclass->name ?? null) !== null
                && ($itemsRegclass->name ?? null) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function emptyStoreAnalyticsPayload(Carbon $from, Carbon $to): array
    {
        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'totals' => [
                'orders' => 0,
                'revenue_cents' => 0,
                'average_order_value_cents' => 0,
            ],
            'charts' => [
                'orders_by_day' => [],
            ],
            'orders' => [],
        ];
    }

    /**
     * @param  array<int, string>  $managedBrandIds
     */
    private function emptyBrandAnalyticsPayload(array $managedBrandIds, Carbon $from, Carbon $to): array
    {
        return [
            'managed_brand_ids' => array_values($managedBrandIds),
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'totals' => [
                'orders' => 0,
                'revenue_cents' => 0,
            ],
            'charts' => [
                'orders_by_day' => [],
            ],
            'brands' => [],
            'orders' => [],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));

        $fromParam = trim((string) $request->query('from', ''));
        $toParam = trim((string) $request->query('to', ''));

        try {
            if ($fromParam !== '' || $toParam !== '') {
                $from = $fromParam !== ''
                    ? Carbon::parse($fromParam)->startOfDay()
                    : Carbon::now()->subDays($days)->startOfDay();
                $to = $toParam !== ''
                    ? Carbon::parse($toParam)->endOfDay()
                    : Carbon::now()->endOfDay();
            } else {
                $to = Carbon::now()->endOfDay();
                $from = Carbon::now()->subDays($days)->startOfDay();
            }
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'from' => $fromParam !== '' ? ['Invalid date format. Use YYYY-MM-DD.'] : [],
                'to' => $toParam !== '' ? ['Invalid date format. Use YYYY-MM-DD.'] : [],
            ]);
        }

        if ($from->gt($to)) {
            throw ValidationException::withMessages([
                'from' => ['from must be before to.'],
                'to' => ['to must be after from.'],
            ]);
        }

        return [$from, $to];
    }
}
