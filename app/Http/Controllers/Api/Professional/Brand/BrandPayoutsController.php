<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandPayoutResource;
use App\Models\Retail\CommissionPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * GET /brand/payouts
 *
 * Paginated payout history for a brand — includes failure lifecycle fields
 * (failure_code, failure_category, stripe_error_code/message, retry counts)
 * introduced in the Phase A2 payout lifecycle work.
 *
 * Authorised via manageWallet — brand-type only.
 */
class BrandPayoutsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $brand = $request->attributes->get('professional');
        Gate::forUser($brand)->authorize('manageWallet', $brand);

        $payouts = CommissionPayout::query()
            ->where('brand_professional_id', $brand->id)
            ->with('affiliateProfessional:id,display_name')
            ->orderByDesc('created_at')
            ->paginate(50);

        return BrandPayoutResource::collection($payouts);
    }
}
