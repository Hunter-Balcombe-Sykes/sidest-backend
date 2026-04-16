<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Actions\Site\UpdateSiteAction;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Professional\Site\UpdateSiteRequest;
use App\Services\Cache\SiteCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
// V2: Site settings management (subdomain, theme, settings JSON, publish status). Powers the mini-site builder.
class ProfessionalSiteController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;
    public function __construct(
        private readonly SiteCacheService $siteCache,
    ) {}

    public function show(Request $request)
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $siteArray = $site->toArray();
        $siteArray = $this->siteCache->hydrateSiteWithBrandTypography($siteArray, (string) $professional->id);
        $siteArray = $this->siteCache->enrichSiteWithBrandPartnerRadius($siteArray);
        return $this->success(['site' => $siteArray]);
    }
    public function update(UpdateSiteRequest $request, UpdateSiteAction $action)
    {
        $professional = $this->currentProfessional($request);
        $data = $request->validated();
        $site = $action->execute($professional, $data);
        $siteArray = $site->toArray();
        $siteArray = $this->siteCache->hydrateSiteWithBrandTypography($siteArray, (string) $professional->id);
        $siteArray = $this->siteCache->enrichSiteWithBrandPartnerRadius($siteArray);

        return $this->success(['site' => $siteArray]);
    }

    /**
     * Dedicated endpoint for booking mode + external URL.
     * Scoped validation so the frontend doesn't need to use the generic site update.
     */
    public function updateBookingSettings(Request $request, UpdateSiteAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'booking_mode' => ['required', 'string', Rule::in(['manual', 'smart'])],
            'manual_booking_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $professional = $this->currentProfessional($request);

        $site = $action->execute($professional, [
            'settings' => [
                'booking_mode' => $validated['booking_mode'],
                'manual_booking_url' => $validated['manual_booking_url'] ?? null,
            ],
        ]);

        $settings = is_array($site->settings) ? $site->settings : [];

        return $this->success([
            'booking_mode' => $settings['booking_mode'] ?? 'manual',
            'manual_booking_url' => $settings['manual_booking_url'] ?? null,
        ]);
    }

    public function visibility(UpdateSiteRequest $request, UpdateSiteAction $action){
        $professional = $this->currentProfessional($request);
        $data = $request->validated();
        $site = $action->execute($professional, $data);
        $siteArray = $site->toArray();
        $siteArray = $this->siteCache->hydrateSiteWithBrandTypography($siteArray, (string) $professional->id);
        $siteArray = $this->siteCache->enrichSiteWithBrandPartnerRadius($siteArray);

        return $this->success(['site' => $siteArray]);
    }
}
