<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Actions\Site\UpdateSiteAction;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateSiteRequest;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;

class StaffSiteManagementController extends ApiController
{
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

        return $this->success(['site' => $site]);
    }
}
