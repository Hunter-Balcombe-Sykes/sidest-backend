<?php

namespace App\Http\Controllers\Api\Professional\Stripe;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Stripe\PayoutsRequest;
use App\Http\Requests\Stripe\CreatePaymentMethodSetupRequest;
use App\Http\Requests\Stripe\OnboardRequest;
use App\Http\Requests\Stripe\SyncPaymentMethodSessionRequest;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

// V2: Stripe Connect Express onboarding, brand payment method, and payout history. Required for the brand-as-merchant-of-record commission flow.
class StripeConnectController extends Controller
{
    public function __construct(
        private readonly StripeConnectService $connectService,
        private readonly CommissionPayoutService $payoutService,
    ) {}

    /**
     * GET /stripe/status
     *
     * Returns the brand/affiliate's Stripe Connect state for the dashboard. Shape:
     *   {
     *     "connect": { status, stripe_connect_account_id, card_payments_active,
     *                  stripe_transfers_active, requirements: [...] },
     *     "has_payment_method": bool,
     *     "payout_method": "card" | "becs" | null,
     *     "masked_card": { "brand": "...", "last4": "..." } | null
     *   }
     *
     * The dropped-column fields (stripe_customer_id, charges_enabled, payouts_enabled,
     * details_submitted) are gone — the frontend reads card_payments_active /
     * stripe_transfers_active off `connect` for the dual-capability state, and reads
     * the saved-PM display fields off the top-level keys.
     */
    public function status(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');

        // ?fresh=1 forces a live Stripe round-trip — used immediately after the
        // Stripe Connect onboarding redirect so the dashboard never renders a
        // pre-onboarding cache hit while the v2.core.account.* webhook is in flight.
        if ($request->boolean('fresh') && $pro->stripe_connect_account_id) {
            StripeConnectService::forgetStatusCache($pro->stripe_connect_account_id);
        }

        $connectStatus = $this->connectService->syncAccountStatus($pro);
        $pro->refresh();

        $hasPaymentMethod = $this->connectService->brandHasPaymentMethod($pro);
        $maskedCard = $hasPaymentMethod && $pro->stripe_payment_method_last4
            ? [
                'brand' => $pro->stripe_payment_method_brand,
                'last4' => $pro->stripe_payment_method_last4,
            ]
            : null;

        return response()->json([
            'connect' => $connectStatus,
            'has_payment_method' => $hasPaymentMethod,
            'payout_method' => $pro->payout_method,
            'masked_card' => $maskedCard,
        ]);
    }

    /**
     * POST /stripe/connect/onboard
     * Creates a Connect Express account and returns the onboarding URL.
     * Used by professionals/influencers to receive payouts.
     */
    public function onboard(OnboardRequest $request): JsonResponse
    {
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
            $reason = in_array($pro->stripe_connect_status, ['active', 'restricted'], true)
                ? 'Could not generate a Stripe Dashboard link right now. Please try again in a moment.'
                : 'Complete Stripe onboarding before opening the dashboard.';

            return response()->json(['error' => $reason], 422);
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

        return response()->json(['status' => 'not_connected']);
    }

    /**
     * POST /stripe/payment-method/setup-checkout
     *
     * Creates a hosted Stripe Checkout setup session that saves a CARD to the brand's
     * v2 Account. Used as the off-session payment source for destination-charge
     * commission payouts. Brand must already have completed Connect onboarding.
     */
    public function createPaymentMethodCheckoutSession(CreatePaymentMethodSetupRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        try {
            $result = $this->connectService->createBrandPaymentMethodSetupSession(
                $pro,
                $request->input('success_url'),
                $request->input('cancel_url'),
            );

            return response()->json($result);
        } catch (\Stripe\Exception\ApiErrorException|\RuntimeException $e) {
            report($e);
            Log::error('Stripe payment method setup session creation failed', [
                'brand_professional_id' => $pro->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /stripe/payment-method/setup-becs
     *
     * Creates a hosted Stripe Checkout setup session that saves a BECS Direct Debit
     * mandate to the brand's v2 Account. Stripe Checkout collects the BSB +
     * account number and renders the mandate acceptance UI automatically.
     *
     * BECS-specific risks: T+2 settlement timing, 7-year dispute window. Platform
     * bears the loss with losses_collector=application. The frontend should explain
     * these tradeoffs in the picker UI before sending the user here.
     */
    public function createBecsCheckoutSession(CreatePaymentMethodSetupRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        try {
            $result = $this->connectService->createBrandBecsSetupSession(
                $pro,
                $request->input('success_url'),
                $request->input('cancel_url'),
            );

            return response()->json($result);
        } catch (\Stripe\Exception\ApiErrorException|\RuntimeException $e) {
            report($e);
            Log::error('Stripe BECS setup session failed', [
                'brand_professional_id' => $pro->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /stripe/payment-method/sync-session
     * Syncs the saved payment method from a completed Checkout setup session on the
     * brand's v2 Account. Handles both card and BECS — payout_method is derived from
     * the PM type Stripe returns.
     */
    public function syncPaymentMethodSession(SyncPaymentMethodSessionRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        try {
            $result = $this->connectService->syncBrandPaymentMethodFromCheckoutSession(
                $pro,
                $request->input('session_id'),
            );

            return response()->json([
                'status' => 'saved',
                ...$result,
            ]);
        } catch (\Stripe\Exception\ApiErrorException|\RuntimeException $e) {
            report($e);
            Log::error('Stripe payment method sync failed', [
                'brand_professional_id' => $pro->id,
                'session_id' => $request->input('session_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /stripe/payment-method
     * Detaches the brand's saved PaymentMethod from their v2 Account and clears the
     * local cache columns.
     */
    public function removePaymentMethod(Request $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        Gate::forUser($pro)->authorize('managePaymentMethod', $pro);

        $this->connectService->removeBrandPaymentMethod($pro);

        return response()->json(['status' => 'removed']);
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

        // Gate first — CommissionPolicy::viewOwnPayouts rejects cross-role calls
        // (affiliate using ?role=brand, or vice versa) with a clean 403 instead of
        // leaking an empty 200. The skeleton carries the role-appropriate id only.
        $skeleton = new CommissionPayout;
        $skeleton->forceFill($role === 'brand'
            ? ['brand_professional_id' => $pro->id]
            : ['affiliate_professional_id' => $pro->id]);
        Gate::forUser($pro)->authorize('viewOwnPayouts', $skeleton);

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
