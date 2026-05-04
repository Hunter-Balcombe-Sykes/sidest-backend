<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Resources\BrandSetupStatusResource;
use App\Jobs\Shopify\SetShopifySetupCompleteJob;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Brand setup wizard completion — validates minimum required fields and marks setup as done.
class BrandSetupController extends ApiController
{
    use ResolveCurrentProfessional;

    public function setupStatus(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

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

    public function completeSetup(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        $brandProfile = BrandProfile::where('professional_id', $professional->id)->first();

        if (! $brandProfile) {
            return $this->error('Brand profile not found. Please update your brand details first.', 404);
        }

        // Validate minimum required fields
        $missing = [];
        if (empty($brandProfile->legal_business_name)) {
            $missing[] = 'legal_business_name';
        }
        if (empty($brandProfile->business_type)) {
            $missing[] = 'business_type';
        }
        if (empty($brandProfile->industries) || ! is_array($brandProfile->industries) || count($brandProfile->industries) === 0) {
            $missing[] = 'industries';
        }

        if (! empty($missing)) {
            return $this->error('Please fill in all required fields before completing setup.', 422, [
                'missing_fields' => $missing,
            ]);
        }

        $brandProfile->setup_complete = true;
        $brandProfile->save();

        // Set the Shopify metafield
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if ($integration) {
            SetShopifySetupCompleteJob::dispatch((string) $integration->id);
        }

        return $this->success([
            'setup_complete' => true,
        ]);
    }
}
