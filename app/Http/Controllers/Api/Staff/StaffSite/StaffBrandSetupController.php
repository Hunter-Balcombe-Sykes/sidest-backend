<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\BrandSetupStatusResource;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Services\Professional\BrandOnboardingReadinessService;
use Illuminate\Http\JsonResponse;

// Staff inspectors for brand-onboarding diagnostics (#BRAND-SETUP-1).
// Mirrors BrandOnboardingReadinessController::show and BrandSetupController::setupStatus
// so support can see whether a brand has cleared every onboarding gate before activating.
class StaffBrandSetupController extends ApiController
{
    public function __construct(
        private readonly BrandOnboardingReadinessService $readinessService,
    ) {}

    /**
     * GET /staff/professionals/{professional}/brand/onboarding-readiness
     */
    public function readiness(Professional $professional): JsonResponse
    {
        return $this->success($this->readinessService->getChecklist($professional));
    }

    /**
     * GET /staff/professionals/{professional}/brand/setup/status
     */
    public function setupStatus(Professional $professional): JsonResponse
    {
        $brandProfile = BrandProfile::where('professional_id', $professional->id)->first();

        $fields = [
            'legal_business_name' => $brandProfile?->legal_business_name ?? null,
            'business_type' => $brandProfile?->business_type ?? null,
            'industries' => $brandProfile?->industries ?? [],
        ];

        $missingFields = [];
        if (empty($fields['legal_business_name'])) {
            $missingFields[] = 'legal_business_name';
        }
        if (empty($fields['business_type'])) {
            $missingFields[] = 'business_type';
        }
        if (empty($fields['industries'])) {
            $missingFields[] = 'industries';
        }

        return $this->success(new BrandSetupStatusResource([
            'setup_complete' => (bool) ($brandProfile?->setup_complete ?? false),
            'fields' => $fields,
            'missing_fields' => $missingFields,
        ]));
    }
}
