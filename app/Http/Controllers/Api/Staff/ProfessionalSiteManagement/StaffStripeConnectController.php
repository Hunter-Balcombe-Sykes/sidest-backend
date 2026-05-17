<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Commerce\CommissionPayout;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff inspector for a brand/affiliate's Stripe-side state.
// Read-only. Covers #STRIPE-PM-1 (paymentMethods, payouts) and #PAYOUT-1 (status).
//
// Field-level safety: every method here returns a column-tight allowlist — never
// the raw \Stripe\Account or PaymentMethod object. Payment-method rows expose
// brand + last4 only — never PAN, expiry, or CVC (Stripe doesn't return those,
// but the column-tight projection is the line of defence if that ever changes).
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
     * GET /staff/professionals/{professional}/stripe/status
     *
     * Read-only Stripe Connect state for a single professional — the support
     * triage view when a commission payout is stuck. Curated allowlist only:
     * the raw `\Stripe\Account` response carries internal IDs and customer-
     * facing requirement payloads that L1 support doesn't need.
     *
     * Field naming: card_payments_active / stripe_transfers_active are the v2
     * dual-capability names (was charges_enabled / payouts_enabled on v1).
     * Aligned with the brand-facing /stripe/status response so the staff
     * inspector and self-service dashboard read the same vocabulary.
     *
     * @return JsonResponse with keys:
     *   - has_account: bool — true when a Connect account exists (regardless of capabilities)
     *   - status: 'not_connected'|'onboarding'|'restricted'|'active'
     *   - card_payments_active: bool — v2 merchant.card_payments capability is active
     *   - stripe_transfers_active: bool — v2 recipient.stripe_transfers capability is active
     *   - requirements_summary: string[] — Stripe requirement codes (already curated by extractRequirements)
     *   - payment_methods_count: int — 0..2, count of (card + becs) PMs on file
     *   - default_payment_method_last4: ?string — last4 of the preferred PM (or null)
     *   - funding_mode: 'auto_charge'|'manual_topup' — stripe_commission_funding_mode column
     */
    public function status(Professional $professional): JsonResponse
    {
        $connect = $this->connectService->syncAccountStatus($professional);
        $professional->refresh();

        $hasCard = ! empty($professional->stripe_card_payment_method_id);
        $hasBecs = ! empty($professional->stripe_becs_payment_method_id);
        $paymentMethodsCount = ($hasCard ? 1 : 0) + ($hasBecs ? 1 : 0);

        // Default last4 mirrors resolveBrandPaymentMethod's selection order:
        // preferred type first, then BECS fallback, then card. Keeps the staff
        // inspector consistent with the PM the platform actually charges.
        $defaultLast4 = null;
        $preferred = $professional->preferred_payout_method;
        if ($preferred === 'card' && $hasCard) {
            $defaultLast4 = $professional->stripe_card_last4;
        } elseif ($preferred === 'becs' && $hasBecs) {
            $defaultLast4 = $professional->stripe_becs_last4;
        } elseif ($hasBecs) {
            $defaultLast4 = $professional->stripe_becs_last4;
        } elseif ($hasCard) {
            $defaultLast4 = $professional->stripe_card_last4;
        }

        return $this->success([
            'has_account' => ($connect['status'] ?? 'not_connected') !== 'not_connected',
            'status' => $connect['status'] ?? 'not_connected',
            'card_payments_active' => (bool) ($connect['card_payments_active'] ?? false),
            'stripe_transfers_active' => (bool) ($connect['stripe_transfers_active'] ?? false),
            'requirements_summary' => $connect['requirements'] ?? [],
            'payment_methods_count' => $paymentMethodsCount,
            'default_payment_method_last4' => $defaultLast4,
            'funding_mode' => $professional->stripe_commission_funding_mode ?? 'auto_charge',
        ]);
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
