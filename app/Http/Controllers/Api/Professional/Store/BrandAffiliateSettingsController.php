<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Retail\BrandAffiliateSettings;
use App\Services\Store\BrandAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BrandAffiliateSettingsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandAccessService $brandAccess,
    ) {}

    /**
     * GET /store/affiliate-settings/{affiliateId}
     * Returns per-affiliate settings for the given affiliate from the brand's perspective.
     */
    public function show(Request $request, string $affiliateId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        [$brandId, $error] = $this->resolveBrandId($request, $pro);
        if ($error !== null) {
            return $error;
        }

        $settings = BrandAffiliateSettings::query()
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->first();

        return $this->success($this->buildPayload($brandId, $affiliateId, $settings));
    }

    /**
     * PATCH /store/affiliate-settings/{affiliateId}
     * Update per-affiliate settings for the given affiliate.
     * Body: { allow_affiliate_media: bool, brand_professional_id?: uuid }
     */
    public function update(Request $request, string $affiliateId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        [$brandId, $error] = $this->resolveBrandId($request, $pro);
        if ($error !== null) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'allow_affiliate_media' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Verify this affiliate is actually linked to the brand.
        $linked = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->exists();

        if (! $linked) {
            return $this->error('Affiliate is not connected to this brand.', 404);
        }

        $settings = BrandAffiliateSettings::updateOrCreate(
            [
                'brand_professional_id'     => $brandId,
                'affiliate_professional_id' => $affiliateId,
            ],
            ['allow_affiliate_media' => (bool) $validator->validated()['allow_affiliate_media']]
        );

        return $this->success($this->buildPayload($brandId, $affiliateId, $settings));
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: string, 1: JsonResponse|null}
     */
    private function resolveBrandId(Request $request, $pro): array
    {
        $requestedId = trim((string) $request->input('brand_professional_id', ''));

        if ($requestedId === '') {
            if ($this->brandAccess->isBrandProfessional($pro)) {
                $requestedId = (string) $pro->id;
            } else {
                return ['', $this->error('brand_professional_id is required for this account type.', 422)];
            }
        }

        if (! $this->brandAccess->canManageBrand($pro, $requestedId)) {
            return ['', $this->error('You are not permitted to manage settings for this brand.', 403)];
        }

        return [$requestedId, null];
    }

    private function buildPayload(string $brandId, string $affiliateId, ?BrandAffiliateSettings $settings): array
    {
        return [
            'brand_professional_id'     => $brandId,
            'affiliate_professional_id' => $affiliateId,
            'allow_affiliate_media'     => $settings ? (bool) $settings->allow_affiliate_media : true,
        ];
    }
}
