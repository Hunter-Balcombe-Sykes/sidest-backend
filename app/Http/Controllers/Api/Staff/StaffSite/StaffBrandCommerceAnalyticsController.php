<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Professional\Analytics\BrandCommerceAnalyticsController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff inspector for a brand's GMV / orders / commission overview (#ANALYTICS-1).
// Delegates to the brand-side controller so the payload + cache key stay identical
// (a single bust covers both endpoints, per the CLAUDE.md commerce-read pattern).
class StaffBrandCommerceAnalyticsController extends ApiController
{
    public function __construct(
        private readonly BrandCommerceAnalyticsController $delegate,
    ) {}

    /**
     * GET /staff/professionals/{professional}/commerce-analytics
     */
    public function overview(Request $request, Professional $professional): JsonResponse
    {
        // The brand controller reads the target via ResolveCurrentProfessional →
        // request attributes; sets it here so the existing logic runs verbatim
        // with no parameter-thread refactor of the brand side.
        $request->attributes->set('professional', $professional);

        return $this->delegate->overview($request);
    }
}
