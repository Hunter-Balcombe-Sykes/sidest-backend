<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Controller;
use App\Models\Commerce\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /brand/billing-summary
 *
 * Returns the brand's commission funding snapshot:
 *   - card presence on their Connect account (stripe_connect_payment_method_id),
 *   - masked card brand/last4 when present,
 *   - blocked order count (approved orders that can't be funded until a card is added).
 *
 * Authorised via manageWallet — brand-type only.
 */
class BrandBillingSummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $brand = $request->attributes->get('professional');
        Gate::forUser($brand)->authorize('manageWallet', $brand);

        // Card-on-file check reads the brand-Connect-scoped column (the card
        // lives on the brand's OWN Connect account, not Partna's platform).
        $hasCard = ! empty($brand->stripe_connect_payment_method_id);

        // Blocked orders only matter when there is no payment method.
        $blockedData = (object) ['cnt' => 0, 'pending_cents' => 0];
        if (! $hasCard) {
            $blockedData = Order::query()
                ->where('brand_professional_id', $brand->id)
                ->where('status', 'approved')
                ->whereNull('payout_id')
                ->where('refund_cents', 0)
                ->where('rate_source', '!=', 'pending')
                ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(commission_cents), 0) AS pending_cents')
                ->first();
        }

        return response()->json([
            'has_card' => $hasCard,
            'masked_card' => $hasCard ? [
                'brand' => $brand->stripe_payment_method_brand,
                'last4' => $brand->stripe_payment_method_last4,
            ] : null,
            'blocked_orders_count' => (int) ($blockedData->cnt ?? 0),
            'blocked_pending_cents' => (int) ($blockedData->pending_cents ?? 0),
            'currency' => 'AUD',
        ]);
    }
}
