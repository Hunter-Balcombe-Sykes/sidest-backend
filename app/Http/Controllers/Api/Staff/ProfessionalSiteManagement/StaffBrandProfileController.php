<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Staff admin updates a brand's profile fields. Only operational fields — sensitive financial config stays in BrandStoreSettings.
class StaffBrandProfileController extends ApiController
{
    /**
     * PATCH /api/staff/professionals/{professional}/brand-profile
     *
     * Updatable: brand_status, affiliate_visibility, setup_complete, legal_business_name, abn, acn, business_website
     */
    public function update(Request $request, Professional $professional): JsonResponse
    {
        $profile = $professional->brandProfile;

        if (! $profile) {
            return $this->error('This professional has no brand profile.', 404);
        }

        $data = $request->validate([
            'brand_status'         => ['sometimes', 'nullable', 'string', 'in:pending,active,suspended,rejected'],
            'affiliate_visibility' => ['sometimes', 'nullable', 'string', 'in:public,invite_only'],
            'setup_complete'       => ['sometimes', 'nullable', 'boolean'],
            'legal_business_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'abn'                  => ['sometimes', 'nullable', 'string', 'max:20'],
            'acn'                  => ['sometimes', 'nullable', 'string', 'max:20'],
            'business_website'     => ['sometimes', 'nullable', 'url', 'max:500'],
        ]);

        foreach ($data as $field => $value) {
            $profile->{$field} = $value;
        }
        $profile->save();

        return $this->success([
            'brand_profile' => [
                'id'                   => $profile->id,
                'brand_status'         => $profile->brand_status,
                'affiliate_visibility' => $profile->affiliate_visibility,
                'setup_complete'       => (bool) $profile->setup_complete,
                'legal_business_name'  => $profile->legal_business_name,
                'abn'                  => $profile->abn,
                'acn'                  => $profile->acn,
                'business_website'     => $profile->business_website,
            ],
        ]);
    }
}
