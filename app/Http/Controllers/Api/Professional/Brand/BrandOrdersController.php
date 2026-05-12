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

        // Join affiliate + customer for the display fields we need per row.
        // Lefts because customer_id can be null on orders without a Shopify customer
        // (rare, but the order webhook upserts without enforcing it).
        $paginator = DB::table('commerce.orders as o')
            ->leftJoin('core.professionals as aff', 'aff.id', '=', 'o.affiliate_professional_id')
            ->leftJoin('core.customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.brand_professional_id', $brandProfessionalId)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->select([
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
            ])
            ->orderByDesc('o.occurred_at')
            ->paginate($perPage);

        $items = collect($paginator->items())->map(function ($row) {
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
        })->values()->all();

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
