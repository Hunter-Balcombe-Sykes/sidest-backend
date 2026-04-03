<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\BrandProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// V2: Brand business profile (ABN, industries, visibility). Used by embedded app wizard during brand onboarding.
class BrandProfileController extends ApiController
{
    use ResolveCurrentProfessional;

    public function show(Request $request)
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $profile = BrandProfile::where('professional_id', $professional->id)->first();

        return $this->success([
            'brand_profile' => $profile,
        ]);
    }

    public function update(Request $request)
    {
        $professional = $this->currentProfessional($request);

        if (! $professional->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $validator = Validator::make($request->all(), [
            'abn' => ['sometimes', 'nullable', 'string', 'max:20'],
            'acn' => ['sometimes', 'nullable', 'string', 'max:20'],
            'legal_business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'business_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'industries' => ['sometimes', 'array', 'max:10'],
            'industries.*' => ['string', 'max:100'],
            'estimated_annual_income' => ['sometimes', 'nullable', 'string', 'max:100'],
            'business_website' => ['sometimes', 'nullable', 'string', 'max:500'],
            'affiliate_visibility' => ['sometimes', 'string', 'in:public,invite_only'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $profile = BrandProfile::updateOrCreate(
            ['professional_id' => $professional->id],
            $validator->validated()
        );

        return $this->success([
            'brand_profile' => $profile->fresh(),
        ]);
    }
}
