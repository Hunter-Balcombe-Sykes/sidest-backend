<?php

namespace App\Http\Controllers\Api\Professional\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Resources\AffiliatePayoutResource;
use App\Models\Retail\CommissionPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * GET /affiliate/payouts
 *
 * Paginated payout history for an affiliate. Mirrors BrandPayoutsController
 * but filters by affiliate_professional_id and uses AffiliatePayoutResource,
 * which hides failure_category='brand_funding' details from the affiliate
 * (they see a generic message rather than the brand's funding state).
 *
 * Authorization: CommissionPolicy::view() — affiliates can view records where
 * affiliate_professional_id matches their own id.
 */
class AffiliatePayoutsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $aff = $request->attributes->get('professional');

        // Authorize via CommissionPolicy::viewOwnPayouts — confirms the actor's
        // id matches the skeleton's affiliate_professional_id. This gives a clean
        // 403 for brands (wrong id) and non-professional accounts before any query.
        // The skeleton carries a non-null affiliate_professional_id so the policy
        // method can perform the id comparison correctly.
        $skeleton = new CommissionPayout;
        $skeleton->forceFill([
            'affiliate_professional_id' => $aff?->id,
        ]);
        Gate::forUser($aff)->authorize('viewOwnPayouts', $skeleton);

        $payouts = CommissionPayout::query()
            ->where('affiliate_professional_id', $aff->id)
            ->with('brandProfessional:id,display_name')
            ->orderByDesc('created_at')
            ->paginate(50);

        return AffiliatePayoutResource::collection($payouts);
    }
}
