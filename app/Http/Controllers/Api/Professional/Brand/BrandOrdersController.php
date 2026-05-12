<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Commerce\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * GET /brand/orders
 *
 * Paginated, all-time list of every order placed through one of the brand's
 * affiliates. Powers /account/commerce?section=payouts (per-order history with
 * pending + paid + reversed all visible).
 *
 * Authorization: query is scoped by brand_professional_id = current professional id.
 * The current.pro middleware proves the actor identity; the scope ensures a brand
 * can only ever see their own orders. Mirrors BrandCommerceAnalyticsController's
 * scope pattern (no per-row Gate check — same data shape, just different projection).
 */
class BrandOrdersController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $brandProfessionalId = (string) $professional->id;

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $statusFilter = $this->parseStatusFilter($request);

        // Join affiliate + customer for the display fields we need per row.
        // Lefts because customer_id can be null on orders without a Shopify customer
        // (rare, but the order webhook upserts without enforcing it).
        $query = DB::table('commerce.orders as o')
            ->leftJoin('core.professionals as aff', 'aff.id', '=', 'o.affiliate_professional_id')
            ->leftJoin('core.customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.brand_professional_id', $brandProfessionalId)
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
     * GET /brand/orders/{order} — full detail for a single order including line items.
     * Used by the Payouts page's row-click modal. Same auth scope as index (brand
     * can only see their own orders); 404 when the id doesn't match (per repo policy:
     * 404 not 403 when "doesn't belong to user").
     */
    public function show(Request $request, string $order): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $brandProfessionalId = (string) $professional->id;

        $row = DB::table('commerce.orders as o')
            ->leftJoin('core.professionals as aff', 'aff.id', '=', 'o.affiliate_professional_id')
            ->leftJoin('core.customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.brand_professional_id', $brandProfessionalId)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->where('o.id', $order)
            ->select($this->rowColumns())
            ->first();

        if (! $row) {
            return $this->error('Order not found', 404);
        }

        $payload = $this->mapRow($row);
        $payload['line_items'] = $this->extractLineItems($row);
        $payload['expected_payout_at'] = $this->deriveExpectedPayoutAt($row);

        return $this->success($payload);
    }

    /**
     * Columns selected for both index() and show(). Centralised so the two
     * stay in sync — both serve the same shape (show just adds line_items).
     *
     * @return array<int, string>
     */
    private function rowColumns(): array
    {
        return [
            'o.id',
            'o.shopify_order_id',
            'o.affiliate_professional_id',
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
            'aff.display_name as affiliate_display_name',
            'aff.handle as affiliate_handle',
            'c.full_name as customer_full_name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(object $row): array
    {
        $shopifyData = is_string($row->shopify_data ?? null)
            ? json_decode($row->shopify_data, true)
            : ($row->shopify_data ?? []);

        return [
            'id' => (string) $row->id,
            'shopify_order_id' => (string) $row->shopify_order_id,
            'shopify_order_name' => (string) (Arr::get($shopifyData, 'name') ?? ''),
            'customer_name' => $this->resolveCustomerName($row),
            'affiliate' => [
                'id' => (string) ($row->affiliate_professional_id ?? ''),
                'display_name' => (string) ($row->affiliate_display_name ?? ''),
                'handle' => $row->affiliate_handle ? (string) $row->affiliate_handle : null,
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
     * Status semantics mirror deriveLifecycleStatus(). EXCLUDED_FROM_AGGREGATES
     * already removes cancelled/voided/refunded rows upstream, so the order_status
     * branch of the derivation is unreachable here — the WHERE only needs the
     * refund-vs-net check + payout_id presence.
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
     * Build the modal's line-items array. Primary source is commerce.order_items —
     * the normalized table maintained by the trg_order_items_diff trigger from the
     * line_items JSONB on commerce.orders. Falls back to parsing shopify_data
     * directly only when the normalized table has no rows for the order (older
     * orders that landed before the trigger was installed), in which case the
     * JSONB shape uses dollar STRING prices that need ×100 to reach cents.
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
     * Best-effort estimate of when the affiliate's commission for this order
     * actually settles (the "grace period ends + payment sent" moment).
     *
     *  - Paid out (payout_id set) → the linked payout's processed_at
     *    (fallback to created_at if processed_at hasn't been stamped yet).
     *  - Reversed → null (no payout will ever happen).
     *  - Pending → occurred_at + partna.store.grace_period_days. This is the
     *    CommissionPayoutService eligibility cutoff: once the order ages past
     *    the grace window, the next payout sweep picks it up.
     */
    private function deriveExpectedPayoutAt(object $row): ?string
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

        $gracePeriodDays = (int) config('partna.store.grace_period_days', 60);

        return \Carbon\Carbon::parse($row->occurred_at)
            ->addDays($gracePeriodDays)
            ->toIso8601String();
    }

    /**
     * Customer is core.customers.full_name when present, otherwise the Shopify
     * order name (e.g. #1042) as a fallback so the row still has a recognisable label.
     */
    private function resolveCustomerName(object $row): string
    {
        $name = trim((string) ($row->customer_full_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $shopifyData = is_string($row->shopify_data ?? null)
            ? json_decode($row->shopify_data, true)
            : ($row->shopify_data ?? []);

        $shopifyName = trim((string) (Arr::get($shopifyData, 'name') ?? ''));

        return $shopifyName !== '' ? $shopifyName : 'Guest';
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
