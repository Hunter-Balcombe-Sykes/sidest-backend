<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateSiteRequest;
use App\Models\Core\Professional\Professional;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Http\JsonResponse;

// V2: Staff updates site settings with force-publish override capability.
class StaffSiteManagementController extends ApiController
{
    public function __construct(
        private readonly SiteCacheService $siteCache
    ) {}

    public function update(StaffUpdateSiteRequest $request, Professional $professional, UpdateSiteAction $action): JsonResponse
    {
        $site = $action->execute(
            $professional,
            $request->validated(),
            [
                'allow_force_publish' => true,
                'allow_subdomain_override' => true,
            ]
        );

        $siteArray = $site->toArray();
        $siteArray = $this->siteCache->enrichSiteWithBrandPartnerRadius($siteArray);

        return $this->success(['site' => $siteArray]);
    }
}
