<?php

namespace App\Http\Controllers\Api\Professional\Stripe;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stripe\OnboardRequest;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

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

    public function startConnect(OnboardRequest $request): JsonResponse
    {
        $aff = $request->attributes->get('professional');

        Gate::forUser($aff)->authorize('startConnect', $aff);

        $url = $this->connectService->createOnboardingLink(
            $aff,
            $request->input('return_url'),
            $request->input('refresh_url'),
        );

        return response()->json(['onboarding_url' => $url]);
    }
}
