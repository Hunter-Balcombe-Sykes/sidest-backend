<?php

namespace App\Http\Controllers\Api\Professional\Stripe;

use App\Http\Controllers\Controller;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeConnectController extends Controller
{
    public function __construct(
        private readonly StripeConnectService $connectService,
        private readonly CommissionPayoutService $payoutService,
    ) {}

    /**
     * GET /stripe/status
     * Returns the current Stripe Connect/Customer status for the professional.
     */
    public function status(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        $connectStatus = $this->connectService->syncAccountStatus($pro);
        $hasPaymentMethod = $this->connectService->brandHasPaymentMethod($pro);

        return response()->json([
            'connect' => $connectStatus,
            'has_payment_method' => $hasPaymentMethod,
            'stripe_customer_id' => $pro->stripe_customer_id,
        ]);
    }

    /**
     * POST /stripe/connect/onboard
     * Creates a Connect Express account and returns the onboarding URL.
     * Used by professionals/influencers to receive payouts.
     */
    public function onboard(Request $request): JsonResponse
    {
        $request->validate([
            'return_url' => 'required|url',
            'refresh_url' => 'required|url',
        ]);

        $pro = $request->attributes->get('professional');

        $url = $this->connectService->createOnboardingLink(
            $pro,
            $request->input('return_url'),
            $request->input('refresh_url'),
        );

        return response()->json(['onboarding_url' => $url]);
    }

    /**
     * POST /stripe/connect/dashboard
     * Returns a Stripe Express dashboard login link.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        $url = $this->connectService->createDashboardLink($pro);

        if (! $url) {
            return response()->json(['error' => 'Dashboard not available. Complete onboarding first.'], 422);
        }

        return response()->json(['dashboard_url' => $url]);
    }

    /**
     * POST /stripe/connect/disconnect
     * Disconnects the Connect account from the platform.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        if (! $pro->stripe_connect_account_id) {
            return response()->json(['error' => 'No Stripe Connect account to disconnect.'], 422);
        }

        $this->connectService->disconnectAccount($pro);

        return response()->json(['status' => 'disconnected']);
    }

    /**
     * POST /stripe/payment-method/setup
     * Creates a SetupIntent so the brand can save a payment method.
     */
    public function setupPaymentMethod(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        $result = $this->connectService->createSetupIntent($pro);

        return response()->json($result);
    }

    /**
     * POST /stripe/payment-method/confirm
     * Saves the payment method after the brand confirms the SetupIntent.
     */
    public function confirmPaymentMethod(Request $request): JsonResponse
    {
        $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        $pro = $request->attributes->get('professional');

        $this->connectService->savePaymentMethod($pro, $request->input('payment_method_id'));

        return response()->json(['status' => 'saved']);
    }

    /**
     * GET /stripe/payment-methods
     * Lists the brand's saved payment methods.
     */
    public function listPaymentMethods(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        $methods = $this->connectService->listPaymentMethods($pro);

        return response()->json(['payment_methods' => $methods]);
    }

    /**
     * DELETE /stripe/payment-method
     * Removes the brand's payment setup.
     */
    public function removePaymentMethod(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        $this->connectService->removeBrandPaymentSetup($pro);

        return response()->json(['status' => 'removed']);
    }

    /**
     * GET /stripe/payouts
     * Lists payout history for the professional.
     */
    public function payouts(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        $role = $request->query('role', 'affiliate'); // 'brand' or 'affiliate'

        $query = CommissionPayout::query()
            ->with(['brandProfessional:id,display_name,handle', 'affiliateProfessional:id,display_name,handle']);

        if ($role === 'brand') {
            $query->where('brand_professional_id', $pro->id);
        } else {
            $query->where('affiliate_professional_id', $pro->id);
        }

        $payouts = $query
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
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

        $summary = $this->payoutService->getPayoutSummary($pro);

        return response()->json([
            'payouts' => $payouts,
            'summary' => $summary,
        ]);
    }
}
