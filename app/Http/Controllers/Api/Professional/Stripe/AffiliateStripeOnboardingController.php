<?php

namespace App\Http\Controllers\Api\Professional\Stripe;

use App\Http\Controllers\Controller;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /affiliate/stripe/connect/start
 *
 * Affiliate-facing Connect Express onboarding link. Thin wrapper around
 * StripeConnectService::createOnboardingLink() that is explicitly scoped
 * to affiliate accounts only — the existing /stripe/connect/onboard route
 * is accessible to all professional types but this endpoint makes the
 * intent unambiguous for frontend routing.
 */
class AffiliateStripeOnboardingController extends Controller
{
    public function __construct(private readonly StripeConnectService $connectService) {}

    public function startConnect(Request $request): JsonResponse
    {
        $request->validate([
            'return_url'  => 'required|url',
            'refresh_url' => 'required|url',
        ]);

        $aff = $request->attributes->get('professional');

        // Only affiliates (professional_type = 'professional') use Connect to
        // receive payouts. Brands fund payouts but don't have Connect accounts.
        if (($aff->professional_type ?? null) === 'brand') {
            abort(403, 'Brand accounts do not use Stripe Connect payout onboarding.');
        }

        $url = $this->connectService->createOnboardingLink(
            $aff,
            $request->input('return_url'),
            $request->input('refresh_url'),
        );

        return response()->json(['onboarding_url' => $url]);
    }
}
