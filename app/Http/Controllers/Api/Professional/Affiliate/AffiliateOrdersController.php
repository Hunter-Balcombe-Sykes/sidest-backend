<?php

namespace App\Http\Controllers\Api\Professional\Affiliate;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Commerce\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * GET /affiliate/orders
 *
 * Paginated, all-time list of every order the affiliate drove. Powers
 * /account/shop?section=payouts (per-order history with pending + paid + reversed
 * all visible). Mirror of BrandOrdersController with brand identity in place of
 * affiliate identity per row.
 *
 * Authorization: query is scoped by affiliate_professional_id = current
 * professional id. Mirrors AffiliateCommerceAnalyticsController's scope pattern.
 */
class AffiliateOrdersController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $affiliateProfessionalId = (string) $professional->id;

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $statusFilter = $this->parseStatusFilter($request);

        $query = DB::table('commerce.orders as o')
            ->leftJoin('core.professionals as brand', 'brand.id', '=', 'o.brand_professional_id')
            ->leftJoin('core.customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.affiliate_professional_id', $affiliateProfessionalId)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->select($this->rowColumns())
            ->orderByDesc('o.occurred_at');

        $this->applyStatusFilter($query, $statusFilter);

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(fn ($row) => $this->mapRow($row))->values()->all();

        return $this->success([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * GET /affiliate/orders/{order} — full detail for a single order including line items.
     * Used by the Payouts page's row-click modal. Same auth scope as index (affiliate
     * can only see their own orders); 404 when the id doesn't match (per repo policy:
     * 404 not 403 when "doesn't belong to user").
     */
    public function show(Request $request, string $order): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $affiliateProfessionalId = (string) $professional->id;

        $row = DB::table('commerce.orders as o')
            ->leftJoin('core.professionals as brand', 'brand.id', '=', 'o.brand_professional_id')
            ->leftJoin('core.customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.affiliate_professional_id', $affiliateProfessionalId)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('o.id', $order)
            ->select($this->rowColumns())
            ->first();

        if (! $row) {
            return $this->error('Order not found', 404);
        }

        $payload = $this->mapRow($row);
        $payload['line_items'] = $this->extractLineItems($row);
        $payload['expected_payout_at'] = $this->deriveExpectedPayoutAt($row, (string) $row->brand_professional_id);

        return $this->success($payload);
    }

    /**
     * @return array<int, string>
     */
    private function rowColumns(): array
    {
        return [
            'o.id',
            'o.shopify_order_id',
            'o.brand_professional_id',
            'o.gross_cents',
            'o.discount_cents',
            'o.refund_cents',
            'o.net_cents',
            'o.commission_cents',
            'o.commission_rate',
            'o.currency_code',
            'o.status as order_status',
            'o.payout_id',
            'o.occurred_at',
            'o.shopify_data',
            'brand.display_name as brand_display_name',
            'brand.handle as brand_handle',
            'c.full_name as customer_full_name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'shopify_order_id' => (string) $row->shopify_order_id,
            'shopify_order_name' => $this->resolveShopifyOrderName($row),
            'customer_name' => $this->resolveCustomerName($row),
            'brand' => [
                'id' => (string) ($row->brand_professional_id ?? ''),
                'display_name' => (string) ($row->brand_display_name ?? ''),
                'handle' => $row->brand_handle ? (string) $row->brand_handle : null,
            ],
            'gross_cents' => (int) $row->gross_cents,
            'discount_cents' => (int) $row->discount_cents,
            'refund_cents' => (int) $row->refund_cents,
            'net_cents' => (int) $row->net_cents,
            'commission_cents' => (int) $row->commission_cents,
            'commission_rate' => (float) $row->commission_rate,
            'currency_code' => strtoupper((string) ($row->currency_code ?? 'AUD')),
            'status' => $this->deriveLifecycleStatus($row),
            'occurred_at' => $row->occurred_at ? \Carbon\Carbon::parse($row->occurred_at)->toIso8601String() : null,
        ];
    }

    /**
     * Parse and validate the ?status= query param. Null when omitted (= no filter).
     */
    private function parseStatusFilter(Request $request): ?string
    {
        $status = $request->query('status');
        if ($status === null || $status === '') {
            return null;
        }
        abort_unless(in_array($status, ['pending', 'paid', 'reversed'], true), 422, 'Invalid status filter.');

        return (string) $status;
    }

    /**
     * Apply the derived-status filter at SQL level so pagination is correct.
     * Mirrors BrandOrdersController::applyStatusFilter — same semantics, same
     * upstream EXCLUDED_FROM_AGGREGATES exclusion.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function applyStatusFilter($query, ?string $status): void
    {
        if (! $status) {
            return;
        }

        $reversed = 'o.refund_cents >= o.net_cents AND o.net_cents > 0';

        match ($status) {
            'reversed' => $query->whereRaw($reversed),
            'paid' => $query->whereRaw("NOT ({$reversed})")->whereNotNull('o.payout_id'),
            'pending' => $query->whereRaw("NOT ({$reversed})")->whereNull('o.payout_id'),
        };
    }

    /**
     * Build the modal's line-items array. Mirrors
     * BrandOrdersController::extractLineItems — primary source is the
     * commerce.order_items normalized table; falls back to parsing
     * shopify_data.line_items JSONB only when the trigger-mirrored rows
     * aren't present (older orders).
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractLineItems(object $row): array
    {
        $items = DB::table('commerce.order_items')
            ->where('order_id', $row->id)
            ->orderBy('shopify_line_item_id')
            ->get();

        if ($items->isNotEmpty()) {
            return $items->map(fn ($item) => [
                'id' => (string) $item->id,
                'title' => (string) $item->title,
                'variant_title' => null,
                'sku' => $item->sku !== null && $item->sku !== '' ? (string) $item->sku : null,
                'quantity' => (int) $item->quantity,
                'price_cents' => (int) $item->unit_price_cents,
                'total_cents' => (int) $item->line_total_cents,
            ])->values()->all();
        }

        $shopifyData = is_string($row->shopify_data ?? null)
            ? json_decode($row->shopify_data, true)
            : ($row->shopify_data ?? []);

        $lineItems = Arr::get($shopifyData, 'line_items', []);
        if (! is_array($lineItems)) {
            return [];
        }

        return collect($lineItems)->map(function ($item) {
            $price = (float) (Arr::get($item, 'price') ?? 0);
            $quantity = (int) (Arr::get($item, 'quantity') ?? 1);

            return [
                'id' => (string) (Arr::get($item, 'id') ?? ''),
                'title' => (string) (Arr::get($item, 'title') ?? Arr::get($item, 'name') ?? ''),
                'variant_title' => Arr::get($item, 'variant_title') !== null ? (string) Arr::get($item, 'variant_title') : null,
                'sku' => Arr::get($item, 'sku') !== null && Arr::get($item, 'sku') !== '' ? (string) Arr::get($item, 'sku') : null,
                'quantity' => $quantity,
                'price_cents' => (int) round($price * 100),
                'total_cents' => (int) round($price * $quantity * 100),
            ];
        })->values()->all();
    }

    /**
     * Mirror of BrandOrdersController::deriveExpectedPayoutAt — same semantics:
     * paid orders return the linked payout's processed_at (or created_at fallback),
     * pending orders estimate occurred_at + the brand's payout_hold_days, fully
     * refunded orders return null.
     *
     * Reads the BRAND's hold setting (not the affiliate's) — payout cadence is
     * determined by the brand whose commission is being paid.
     */
    private function deriveExpectedPayoutAt(object $row, string $brandProfessionalId): ?string
    {
        if (! empty($row->payout_id)) {
            $payout = DB::table('commerce.commission_payouts')
                ->where('id', $row->payout_id)
                ->select(['processed_at', 'created_at'])
                ->first();

            if (! $payout) {
                return null;
            }

            $at = $payout->processed_at ?? $payout->created_at;

            return $at ? \Carbon\Carbon::parse($at)->toIso8601String() : null;
        }

        if ((int) $row->refund_cents >= (int) $row->net_cents && (int) $row->net_cents > 0) {
            return null;
        }

        if (! $row->occurred_at) {
            return null;
        }

        $holdDays = $this->resolveBrandHoldDays($brandProfessionalId);

        return \Carbon\Carbon::parse($row->occurred_at)
            ->addDays($holdDays)
            ->toIso8601String();
    }

    /**
     * Brand-level payout_hold_days (the brand whose commission is being paid),
     * falling back to the system default when the brand has no row yet.
     */
    private function resolveBrandHoldDays(string $brandProfessionalId): int
    {
        $brandValue = DB::table('brand.brand_store_settings')
            ->where('professional_id', $brandProfessionalId)
            ->value('payout_hold_days');

        return max(0, (int) ($brandValue ?? config('partna.store.payout_hold_days', 7)));
    }

    private function resolveCustomerName(object $row): string
    {
        $name = trim((string) ($row->customer_full_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $this->resolveShopifyOrderName($row) ?: 'Guest';
    }

    private function resolveShopifyOrderName(object $row): string
    {
        $shopifyData = is_string($row->shopify_data ?? null)
            ? json_decode($row->shopify_data, true)
            : ($row->shopify_data ?? []);

        return (string) (Arr::get($shopifyData, 'name') ?? '');
    }

    /**
     * Maps order aggregate state → ledger-flavoured lifecycle pill for the UI.
     * Identical semantics to EmbeddedOrderAnalyticsController::deriveLineStatus.
     */
    private function deriveLifecycleStatus(object $row): string
    {
        if (in_array($row->order_status, ['cancelled', 'voided', 'refunded'], true)) {
            return 'reversed';
        }
        if ((int) $row->refund_cents >= (int) $row->net_cents && (int) $row->net_cents > 0) {
            return 'reversed';
        }
        if (! empty($row->payout_id)) {
            return 'paid';
        }

        return 'pending';
    }
}
