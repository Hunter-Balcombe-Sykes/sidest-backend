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

        $paginator = DB::table('commerce.orders as o')
            ->leftJoin('core.professionals as brand', 'brand.id', '=', 'o.brand_professional_id')
            ->leftJoin('core.customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.affiliate_professional_id', $affiliateProfessionalId)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->select([
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
            ])
            ->orderByDesc('o.occurred_at')
            ->paginate($perPage);

        $items = collect($paginator->items())->map(function ($row) {
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
