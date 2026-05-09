<?php

namespace App\Http\Controllers\Api\Professional\Stripe;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Stripe\PayoutsRequest;
use App\Http\Requests\Stripe\ConfirmTopUpCheckoutRequest;
use App\Http\Requests\Stripe\CreatePaymentMethodSetupRequest;
use App\Http\Requests\Stripe\CreateTopUpCheckoutRequest;
use App\Http\Requests\Stripe\SyncPaymentMethodSessionRequest;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

// V2: Stripe Connect Express onboarding, payment methods, commission wallet top-ups, and payout history. Required for the 80/20 affiliate payout split.
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
        $pro->refresh();

        return response()->json([
            'connect' => $connectStatus,
            'has_payment_method' => $hasPaymentMethod,
            'stripe_customer_id' => $pro->stripe_customer_id,
            'funding_mode' => $pro->stripe_commission_funding_mode ?? 'auto_charge',
            'manual_balance' => [
                'cents' => (int) ($pro->stripe_manual_balance_cents ?? 0),
                'currency_code' => strtoupper((string) ($pro->stripe_manual_balance_currency ?: 'AUD')),
            ],
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
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        $result = $this->connectService->createSetupIntent($pro);

        return response()->json($result);
    }

    /**
     * POST /stripe/payment-method/setup-checkout
     * Creates a hosted Stripe Checkout setup session for brand payment method setup.
     */
    public function createPaymentMethodCheckoutSession(CreatePaymentMethodSetupRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        $result = $this->connectService->createPaymentMethodSetupCheckoutSession(
            $pro,
            $request->input('success_url'),
            $request->input('cancel_url'),
        );

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
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        $this->connectService->savePaymentMethod($pro, $request->input('payment_method_id'));

        return response()->json(['status' => 'saved']);
    }

    /**
     * POST /stripe/payment-method/sync-session
     * Syncs the default payment method from a completed Checkout setup session.
     */
    public function syncPaymentMethodSession(SyncPaymentMethodSessionRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        try {
            $result = $this->connectService->syncPaymentMethodFromCheckoutSession(
                $pro,
                $request->input('session_id'),
            );

            return response()->json([
                'status' => 'saved',
                ...$result,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /stripe/payment-methods
     * Lists the brand's saved payment methods.
     */
    public function listPaymentMethods(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

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
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        $this->connectService->removeBrandPaymentSetup($pro);

        return response()->json(['status' => 'removed']);
    }

    /**
     * PATCH /stripe/funding-mode
     * Set brand commission funding mode: auto_charge or manual_topup.
     */
    public function updateFundingMode(Request $request): JsonResponse
    {
        $request->validate([
            'mode' => 'required|string|in:auto_charge,manual_topup',
        ]);

        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('manageWallet', $pro);

        $mode = $request->input('mode');

        $this->connectService->setCommissionFundingMode($pro, $mode);

        return response()->json([
            'status' => 'updated',
            'mode' => $mode,
        ]);
    }

    /**
     * POST /stripe/topups/checkout
     * Creates hosted Stripe Checkout session to manually top up commission wallet.
     */
    public function createTopUpCheckoutSession(CreateTopUpCheckoutRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('topUp', $pro);

        $result = $this->connectService->createManualTopUpCheckoutSession(
            $pro,
            (int) $request->input('amount_cents'),
            strtoupper((string) $request->input('currency_code', 'AUD')),
            $request->input('success_url'),
            $request->input('cancel_url'),
        );

        return response()->json($result);
    }

    /**
     * POST /stripe/topups/confirm
     * Confirms a completed top-up Checkout session and credits the wallet.
     */
    public function confirmTopUpCheckoutSession(ConfirmTopUpCheckoutRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('topUp', $pro);

        try {
            $result = $this->connectService->confirmManualTopUpCheckoutSession(
                $pro,
                $request->input('session_id'),
            );

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /stripe/payouts
     * Lists payout history for the professional.
     *
     * TODO: expose commission ledger entries as a sibling endpoint so brands
     * can reconcile "why is this payout $X?" against individual commission
     * rows. The CommissionMovement model, commerce.commission_movements
     * table, and CommissionPayoutService already exist — the only thing
     * missing is the HTTP layer (controller method + route + resource + test).
     * Deferred until a real brand asks; when promoting, decide between:
     *   a) nested under a specific payout ID (GET /stripe/payouts/{id}/entries)
     *      — simplest, answers the "reconcile this one payout" question.
     *   b) a top-level list with filter params (GET /stripe/commissions?
     *      payout_id=&date_from=&date_to=&status=) — more flexible but more
     *      surface area to validate.
     * Option (a) is probably the right first move; (b) can grow from it.
     */
    public function payouts(PayoutsRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        $role = $request->input('role');

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
                // Per-payout grace deadline. Affiliate side renders this as
                // "expires in N days" badge on rows where the affiliate
                // hasn't connected Stripe yet; brand side uses it for
                // reporting visibility.
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

        $summary = $this->payoutService->getPayoutSummary($pro);

        return response()->json([
            'payouts' => $payouts,
            'summary' => $summary,
        ]);
    }
}
