<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Professional\Brand\BrandOnboardingReadinessService;
use Illuminate\Http\Request;

// V2: Returns brand setup checklist (images uploaded, Shopify connected, Stripe connected). Gates brand activation.
class BrandOnboardingReadinessController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandOnboardingReadinessService $readinessService,
    ) {}

    public function show(Request $request)
    {
        $professional = $this->currentProfessional($request);

        return $this->success($this->readinessService->getChecklist($professional));
    }
}
