<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Professional\BrandPartnerLinkLifecycleService;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Professional\BrandPartnerSiteSettingsSync;
use App\Services\Professional\DTO\DisconnectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

// V2: Affiliate connects to/disconnects from brand partners. Simplified to single-brand model in V2.
class BrandPartnerController extends ApiController
{
    use NormalizesPerPage;
    use ResolveCurrentProfessional;
    use ReturnsPaginatedResponse;

    public function connect(
        Request $request,
        string $brandProfessionalId,
        BrandPartnerLinkService $brandPartnerLinks,
        BrandPartnerSiteSettingsSync $sync,
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $brand = Professional::query()
            ->whereKey($brandProfessionalId)
            ->where('professional_type', 'brand')
            ->where('status', 'active')
            ->first();

        if (! $brand) {
            return $this->error('Brand partner not found.', 404);
        }

        $brandProfile = $brand->brandProfile;

        $brandStatus = $brandProfile?->brand_status ?? 'deactivated';
        if ($brandStatus === 'deactivated') {
            return $this->error('This brand is not currently accepting new connections.', 403);
        }

        try {
            $link = $brandPartnerLinks->connectBrandToAffiliate((string) $professional->id, (string) $brandProfessionalId);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $sync->sync($site, (string) $professional->id);
        $sync->invalidateAffiliateCaches($site);

        return $this->success([
            'connected' => true,
            'brand_professional_id' => $brandProfessionalId,
            'slot' => (int) $link->slot,
        ]);
    }

    public function index(Request $request, BrandPartnerLinkService $brandPartnerLinks): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $perPage = $this->normalizePerPage($request, 25, 100);

        $page = Professional::query()
            ->where('professional_type', 'brand')
            ->where('status', 'active')
            ->whereHas('brandProfile', fn ($q) => $q->where('affiliate_visibility', 'public')->where('brand_status', 'active'))
            ->with('site')
            ->orderByRaw('COALESCE(display_name, handle) asc')
            ->paginate($perPage)
            ->appends($request->query());

        $brands = $page->getCollection()
            ->map(fn (Professional $p): array => $this->brandToArray($p))
            ->keyBy('id');

        // Always include brands the caller is already connected to, regardless of visibility,
        // so the frontend can display their logo and name on the settings card.
        $connectedIds = $brandPartnerLinks->getLinksForAffiliate((string) $professional->id)
            ->pluck('brand_professional_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->diff($brands->keys())
            ->values();

        if ($connectedIds->isNotEmpty()) {
            Professional::query()
                ->whereIn('id', $connectedIds->all())
                ->where('professional_type', 'brand')
                ->with('site')
                ->get()
                ->each(function (Professional $p) use ($brands): void {
                    $brands->put((string) $p->id, $this->brandToArray($p));
                });
        }

        $payload = $this->paginatedResponse($page, 'brands');
        $payload['brands'] = $brands->values()->all();

        return $this->success($payload);
    }

    public function promote(
        Request $request,
        string $brandProfessionalId,
        BrandPartnerLinkService $brandPartnerLinks,
        BrandPartnerSiteSettingsSync $sync,
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $promoted = $brandPartnerLinks->promoteBrandToPrimary((string) $professional->id, (string) $brandProfessionalId);
        if (! $promoted) {
            return $this->error('Brand partner not found in your additional partners.', 404);
        }

        $sync->sync($site, (string) $professional->id);
        $sync->invalidateAffiliateCaches($site);

        return $this->success([
            'promoted' => true,
            'primary_professional_id' => $brandProfessionalId,
        ]);
    }

    public function disconnect(
        Request $request,
        string $brandProfessionalId,
        BrandPartnerLinkLifecycleService $lifecycle,
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $brand = Professional::query()->whereKey($brandProfessionalId)->first();
        if (! $brand) {
            return $this->error('Brand partner not found.', 404);
        }

        $result = $lifecycle->disconnect(DisconnectRequest::forAffiliate(
            brand: $brand,
            affiliate: $professional,
            reason: $data['reason'] ?? null,
        ));

        if (! $result->disconnected) {
            return $this->error('Brand partner not found in your connections.', 404);
        }

        $response = [
            'disconnected' => true,
            'brand_professional_id' => $brandProfessionalId,
            'selections_removed' => $result->selectionsRemoved,
        ];

        if ($result->staleSettingsCleaned) {
            $response['stale_settings_cleaned'] = true;
        }

        return $this->success($response);
    }

    private function brandToArray(Professional $professional): array
    {
        $siteSettings = is_array($professional->site?->settings ?? null) ? $professional->site->settings : [];
        $designSettings = is_array($siteSettings['design'] ?? null) ? $siteSettings['design'] : [];
        $mediaSettings = is_array($designSettings['media'] ?? null) ? $designSettings['media'] : [];

        return [
            'id' => $professional->id,
            'display_name' => $professional->display_name,
            'handle' => $professional->handle,
            'subdomain' => $professional->site?->subdomain,
            'brand_logo_url' => is_string($mediaSettings['brand_logo_url'] ?? $mediaSettings['brandLogoUrl'] ?? null)
                ? ($mediaSettings['brand_logo_url'] ?? $mediaSettings['brandLogoUrl'])
                : null,
            'brand_color' => is_string($designSettings['dark_color'] ?? $designSettings['darkColor'] ?? null)
                ? ($designSettings['dark_color'] ?? $designSettings['darkColor'])
                : null,
            'brand_contrast_color' => is_string($designSettings['white_color'] ?? $designSettings['whiteColor'] ?? null)
                ? ($designSettings['white_color'] ?? $designSettings['whiteColor'])
                : null,
        ];
    }

}
