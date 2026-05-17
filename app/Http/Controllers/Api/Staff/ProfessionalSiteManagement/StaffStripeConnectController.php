<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Commerce\CommissionPayout;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff inspector for a brand/affiliate's Stripe-side state (#STRIPE-PM-1).
//
// Read-only. Future #PAYOUT-1 session extends this controller with a curated
// status read (charges_enabled, payouts_enabled, requirements_summary, etc.) —
// the column-tight field allowlist must stay in this controller so a forgotten
// caller can't accidentally leak raw Stripe responses.
//
// Field-level safety: payment-method rows expose brand + last4 only — never PAN,
// expiry, or CVC (Stripe doesn't return those, but this comment is here so the
// next contributor doesn't widen the projection by accident).
class StaffStripeConnectController extends ApiController
{
    public function __construct(
        private readonly StripeConnectService $connectService,
    ) {}

    /**
     * GET /staff/professionals/{professional}/stripe/payment-methods
     * Any-staff. Returns brand + last4 only; safe to expose to L1 support.
     * Non-brand professionals return an empty list (rather than 403) — staff
     * inspecting a non-brand is a legitimate triage path and 403 would mislead.
     */
    public function paymentMethods(Professional $professional): JsonResponse
    {
        if (($professional->professional_type ?? null) !== 'brand') {
            return $this->success(['payment_methods' => []]);
        }

        $methods = $this->connectService->listPaymentMethods($professional);

        return $this->success(['payment_methods' => $methods]);
    }

    /**
     * GET /staff/professionals/{professional}/stripe/payouts
     * Any-staff. Lists all payouts where this professional is either the brand
     * or the affiliate side of the row. Mirrors the shape of the brand-side
     * /stripe/payouts response; capped at 50 rows ordered by most-recent.
     */
    public function payouts(Request $request, Professional $professional): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query('limit', 50)));

        $rows = CommissionPayout::query()
            ->with([
                'brandProfessional:id,display_name,handle',
                'affiliateProfessional:id,display_name,handle',
            ])
            ->where(function ($q) use ($professional): void {
                $q->where('brand_professional_id', $professional->id)
                    ->orWhere('affiliate_professional_id', $professional->id);
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $payouts = $rows->map(fn (CommissionPayout $p): array => [
            'id' => $p->id,
            'status' => $p->status,
            'gross_commission_cents' => $p->gross_commission_cents,
            'platform_fee_cents' => $p->platform_fee_cents,
            'net_payout_cents' => $p->net_payout_cents,
            'currency_code' => $p->currency_code,
            'ledger_entry_count' => $p->ledger_entry_count,
            'failure_reason' => $p->failure_reason,
            'eligible_after' => $p->eligible_after?->toIso8601String(),
            'processed_at' => $p->processed_at?->toIso8601String(),
            'void_at' => $p->void_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
            'brand' => $p->brandProfessional ? [
                'id' => $p->brandProfessional->id,
                'name' => $p->brandProfessional->display_name,
                'handle' => $p->brandProfessional->handle,
            ] : null,
            'affiliate' => $p->affiliateProfessional ? [
                'id' => $p->affiliateProfessional->id,
                'name' => $p->affiliateProfessional->display_name,
                'handle' => $p->affiliateProfessional->handle,
            ] : null,
        ]);

        return $this->success([
            'payouts' => $payouts,
        ]);
    }
}
