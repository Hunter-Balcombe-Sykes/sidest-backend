<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Controller;
use App\Models\Commerce\Order;
use App\Models\Commerce\WalletMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /brand/billing-summary
 *
 * Returns a brand's billing snapshot: card presence, wallet balance,
 * blocked order count (orders approved with no payment method on file),
 * and the 5 most recent manual wallet top-ups.
 *
 * Authorised via manageWallet — brand-type only.
 */
class BrandBillingSummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $brand = $request->attributes->get('professional');
        Gate::forUser($brand)->authorize('manageWallet', $brand);

        $hasCard = ! empty($brand->stripe_payment_method_id);

        // Blocked orders only matter when there is no payment method: the brand
        // has approved orders that can't be funded until they add a card.
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

        // Most-recent 5 wallet top-ups for the "recent activity" card.
        $recentTopups = WalletMovement::query()
            ->where('professional_id', $brand->id)
            ->where('reason', 'top_up')
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get(['id', 'amount_cents', 'currency_code', 'occurred_at'])
            ->map(fn ($m) => [
                'id'            => $m->id,
                'amount_cents'  => (int) $m->amount_cents,
                'currency_code' => $m->currency_code,
                'occurred_at'   => $m->occurred_at?->toIso8601String(),
            ]);

        return response()->json([
            'has_card'              => $hasCard,
            'masked_card'           => $hasCard ? [
                'brand' => $brand->stripe_payment_method_brand,
                'last4' => $brand->stripe_payment_method_last4,
            ] : null,
            'wallet_balance_cents'  => (int) ($brand->stripe_manual_balance_cents ?? 0),
            'currency'              => strtoupper((string) ($brand->stripe_manual_balance_currency ?: 'AUD')),
            'blocked_orders_count'  => (int) ($blockedData->cnt ?? 0),
            'blocked_pending_cents' => (int) ($blockedData->pending_cents ?? 0),
            'recent_topups'         => $recentTopups,
        ]);
    }
}
