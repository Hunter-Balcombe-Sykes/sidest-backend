<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Professional\Store\AffiliateProductController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff inspector for an affiliate's product selections (#AFF-SEL-1, read part).
// Delegates to the affiliate-side AffiliateProductController::index so the same
// catalog+selection-state merge runs (selected variant gids, custom photo flag,
// brand default commission, etc.).
//
// reset-to-defaults POST is an admin write and is intentionally out of scope.
class StaffAffiliateSelectionController extends ApiController
{
    public function __construct(
        private readonly AffiliateProductController $delegate,
    ) {}

    /**
     * GET /staff/professionals/{professional}/affiliate/selections
     */
    public function index(Request $request, Professional $professional): JsonResponse
    {
        $request->attributes->set('professional', $professional);

        return $this->delegate->index($request);
    }
}
