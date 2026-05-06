<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Retail\CommissionMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Backs the affiliate-order-block Shopify admin UI extension.
// Returns the affiliate + per-line-item commission breakdown for a single
// Shopify order, scoped to the brand resolved by the auth middleware.
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

        // Strip the GID prefix if Shopify hands us one — ledger stores numeric IDs.
        $orderId = (string) preg_replace('#^gid://shopify/Order/#', '', $shopifyOrderId);

        $entries = CommissionMovement::with('affiliateProfessional:id,display_name')
            ->where('brand_professional_id', $professionalId)
            ->where('shopify_order_id', $orderId)
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
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

        // Every entry on a single order shares the same affiliate by construction
        // (the order is attributed once at checkout). Pull from the first row.
        $first = $entries->first();
        $affiliate = $first->affiliateProfessional;
        $affiliateSlug = (string) ($first->calculation_metadata['affiliate_slug'] ?? '');

        $totalCommission = 0;
        $totalRevenue = 0;
        $statusSummary = $this->emptyStatusSummary();
        $lineItems = [];

        foreach ($entries as $entry) {
            $meta = is_array($entry->calculation_metadata) ? $entry->calculation_metadata : [];
            $rate = (float) $entry->commission_rate;
            $linePost = (float) ($meta['line_price_post_discount'] ?? 0);
            $revenueCents = (int) round($linePost * 100);

            $totalCommission += $entry->amount_cents;
            $totalRevenue += $revenueCents;

            $status = (string) $entry->status;
            if (isset($statusSummary[$status])) {
                $statusSummary[$status]++;
            }

            $lineItems[] = [
                'line_item_id' => (string) ($meta['line_item_id'] ?? ''),
                'product_id' => (string) ($meta['product_id'] ?? ''),
                'product_title' => (string) ($meta['product_title'] ?? ''),
                'variant_title' => (string) ($meta['variant_title'] ?? ''),
                'quantity' => (int) ($meta['quantity'] ?? 0),
                'revenue_cents' => $revenueCents,
                'commission_rate' => $rate,
                'commission_cents' => (int) $entry->amount_cents,
                'status' => $status,
            ];
        }

        return $this->success([
            'order_id' => $orderId,
            'has_affiliate' => true,
            'affiliate' => $affiliate ? [
                'id' => (string) $affiliate->id,
                'display_name' => (string) $affiliate->display_name,
                'slug' => $affiliateSlug,
            ] : null,
            'currency_code' => (string) ($first->currency_code ?? 'AUD'),
            'total_commission_cents' => $totalCommission,
            'total_revenue_cents' => $totalRevenue,
            'status_summary' => $statusSummary,
            'line_items' => $lineItems,
        ]);
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
