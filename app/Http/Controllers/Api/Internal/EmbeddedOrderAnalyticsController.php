<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Commerce\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Backs the affiliate-order-block Shopify admin UI extension.
// Returns the affiliate + per-line-item commission breakdown for a single
// Shopify order, scoped to the brand resolved by the auth middleware.
//
// Source of truth (post-Phase-4): commerce.orders + commerce.order_items.
// commission_movements is narrowed to money movements (payouts/clawbacks/
// adjustments) and no longer holds per-line accrual rows, so we derive
// everything from the order aggregate.
//
// Auth: any middleware that attaches `embedded_professional_id` (the embedded
// API key middleware for server-to-server, or the Shopify session-token
// middleware for in-extension calls).
class EmbeddedOrderAnalyticsController extends ApiController
{
    /**
     * @return JsonResponse {
     *                      order_id: string,
     *                      has_affiliate: bool,
     *                      affiliate: { id, display_name, slug } | null,
     *                      currency_code: string,
     *                      total_commission_cents: int,
     *                      total_revenue_cents: int,
     *                      status_summary: { pending: int, approved: int, paid: int, reversed: int },
     *                      line_items: Array<{
     *                      line_item_id, product_id, product_title, variant_title,
     *                      quantity, revenue_cents, commission_rate, commission_cents, status
     *                      }>,
     *                      }
     */
    public function show(Request $request, string $shopifyOrderId): JsonResponse
    {
        $professionalId = (string) $request->attributes->get('embedded_professional_id');

        // Strip the GID prefix if Shopify hands us one — orders table stores numeric IDs.
        $orderId = (string) preg_replace('#^gid://shopify/Order/#', '', $shopifyOrderId);

        $order = Order::query()
            ->with([
                'items',
                'affiliateProfessional:id,display_name,handle',
            ])
            ->where('brand_professional_id', $professionalId)
            ->where('shopify_order_id', $orderId)
            ->first();

        if (! $order || ! $order->affiliate_professional_id) {
            return $this->success([
                'order_id' => $orderId,
                'has_affiliate' => false,
                'affiliate' => null,
                'currency_code' => 'AUD',
                'total_commission_cents' => 0,
                'total_revenue_cents' => 0,
                'status_summary' => $this->emptyStatusSummary(),
                'line_items' => [],
            ]);
        }

        $affiliate = $order->affiliateProfessional;
        $lineStatus = $this->deriveLineStatus($order);

        $statusSummary = $this->emptyStatusSummary();
        $lineItems = [];
        $totalCommission = 0;
        $totalRevenue = 0;

        foreach ($order->items as $item) {
            $revenueCents = (int) $item->line_total_cents;
            $commissionCents = (int) $item->commission_cents;

            $totalRevenue += $revenueCents;
            $totalCommission += $commissionCents;
            $statusSummary[$lineStatus]++;

            $lineItems[] = [
                'line_item_id' => (string) $item->shopify_line_item_id,
                'product_id' => (string) $item->shopify_product_id,
                'product_title' => (string) $item->title,
                // order_items doesn't store variant_title — the UI gracefully omits when empty.
                'variant_title' => '',
                'quantity' => (int) $item->quantity,
                'revenue_cents' => $revenueCents,
                'commission_rate' => (float) $item->commission_rate,
                'commission_cents' => $commissionCents,
                'status' => $lineStatus,
            ];
        }

        return $this->success([
            'order_id' => $orderId,
            'has_affiliate' => true,
            'affiliate' => [
                'id' => (string) $affiliate->id,
                'display_name' => (string) $affiliate->display_name,
                'slug' => (string) ($affiliate->handle ?? ''),
            ],
            'currency_code' => (string) ($order->currency_code ?? 'AUD'),
            'total_commission_cents' => $totalCommission,
            'total_revenue_cents' => $totalRevenue,
            'status_summary' => $statusSummary,
            'line_items' => $lineItems,
        ]);
    }

    /**
     * Map order aggregate state → the four ledger-flavoured statuses the UI
     * block already renders. Every line item on an order shares this status
     * (Shopify orders settle as a unit; per-line accrual rows no longer exist).
     */
    private function deriveLineStatus(Order $order): string
    {
        if (in_array($order->status, ['cancelled', 'voided', 'refunded'], true)) {
            return 'reversed';
        }
        if ((int) $order->refund_cents >= (int) $order->net_cents && (int) $order->net_cents > 0) {
            return 'reversed';
        }
        if (! empty($order->payout_id)) {
            return 'paid';
        }

        return 'pending';
    }

    /**
     * @return array<string, int>
     */
    private function emptyStatusSummary(): array
    {
        return [
            'pending' => 0,
            'approved' => 0,
            'paid' => 0,
            'reversed' => 0,
        ];
    }
}
