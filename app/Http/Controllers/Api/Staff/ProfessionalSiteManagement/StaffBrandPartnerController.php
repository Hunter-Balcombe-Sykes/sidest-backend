<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\BrandPartnerSiteSettingsSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2 B6 #BRAND-INVITE-PROMOTE-1: Staff mirror of `BrandPartnerController::promote`.
// Lets support promote an additional brand-partner link to the primary slot on behalf
// of an affiliate when the affiliate is stuck and asks for help.
class StaffBrandPartnerController extends ApiController
{
    /**
     * POST /api/staff/professionals/{affiliate}/brand-partners/{brand}/promote
     *
     * Admin-only. Mirrors self-service promote semantics — must be an existing additional
     * partner; brand or other professional types can't be promoted via this path.
     */
    public function promote(
        Request $request,
        Professional $affiliate,
        Professional $brand,
        BrandPartnerLinkService $brandPartnerLinks,
        BrandPartnerSiteSettingsSync $sync,
    ): JsonResponse {
        if (mb_strtolower(trim((string) $affiliate->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot manage brand partner connections.', 422);
        }

        $site = Site::query()->where('professional_id', $affiliate->id)->first();
        if (! $site) {
            return $this->error('Site not found for this affiliate.', 404);
        }

        $promoted = $brandPartnerLinks->promoteBrandToPrimary((string) $affiliate->id, (string) $brand->id);
        if (! $promoted) {
            return $this->error('Brand partner not found in this affiliate\'s additional partners.', 404);
        }

        $sync->sync($site, (string) $affiliate->id);
        $sync->invalidateAffiliateCaches($site);

        return $this->success([
            'promoted' => true,
            'affiliate_professional_id' => $affiliate->id,
            'primary_professional_id' => $brand->id,
        ]);
    }
}
