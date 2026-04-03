<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\BrandPartnerLinkService;
use App\Services\Store\SelectionCleanupService;
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
        BrandPartnerLinkService $brandPartnerLinks
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

        $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
        $this->invalidateAffiliateCaches($site);

        return $this->success([
            'connected' => true,
            'brand_professional_id' => $brandProfessionalId,
            'slot' => (int) $link->slot,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
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
            ->map(function (Professional $professional): array {
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
            })
            ->values()
            ->all();

        $payload = $this->paginatedResponse($page, 'brands');
        $payload['brands'] = $brands;

        return $this->success($payload);
    }

    public function promote(
        Request $request,
        string $brandProfessionalId,
        BrandPartnerLinkService $brandPartnerLinks
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

        $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
        $this->invalidateAffiliateCaches($site);

        return $this->success([
            'promoted' => true,
            'primary_professional_id' => $brandProfessionalId,
        ]);
    }

    public function disconnect(
        Request $request,
        string $brandProfessionalId,
        BrandPartnerLinkService $brandPartnerLinks,
        SelectionCleanupService $selectionCleanup
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $affiliateProfessionalId = (string) $professional->id;
        $disconnected = $brandPartnerLinks->disconnectBrandFromAffiliate($affiliateProfessionalId, (string) $brandProfessionalId);

        if (! $disconnected) {
            $cleanedStaleSettings = $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, $affiliateProfessionalId);
            if (! $this->settingsStillReferenceBrand($site, (string) $brandProfessionalId) && $cleanedStaleSettings) {
                $this->invalidateAffiliateCaches($site);

                return $this->success([
                    'disconnected' => true,
                    'brand_professional_id' => $brandProfessionalId,
                    'stale_settings_cleaned' => true,
                ]);
            }

            return $this->error('Brand partner not found in your connections.', 404);
        }

        $selectionCleanup->removeSelectionsForAffiliateBrand(
            $affiliateProfessionalId,
            (string) $brandProfessionalId,
            'Brand connection removed',
            '{count} selected product(s) were removed because this brand connection ended.'
        );

        $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, $affiliateProfessionalId);
        $this->invalidateAffiliateCaches($site);

        return $this->success([
            'disconnected' => true,
            'brand_professional_id' => $brandProfessionalId,
        ]);
    }

    private function syncSiteBrandPartnerSettings(
        Site $site,
        BrandPartnerLinkService $brandPartnerLinks,
        string $affiliateProfessionalId
    ): bool {
        $links = $brandPartnerLinks->getLinksForAffiliate($affiliateProfessionalId);
        $settings = is_array($site->settings) ? $site->settings : [];
        $originalSettings = $settings;

        $brandPartner = is_array($settings['brand_partner'] ?? null)
            ? $settings['brand_partner']
            : [];

        $primaryLink = $links->firstWhere('slot', BrandPartnerLinkService::PRIMARY_SLOT);
        if ($primaryLink) {
            $brandPartner['professional_id'] = (string) $primaryLink->brand_professional_id;
        } else {
            unset($brandPartner['professional_id'], $brandPartner['professionalId']);
        }

        $settings['brand_partner'] = $brandPartner;
        $settings['additional_brand_partners'] = $links
            ->filter(static fn ($link): bool => (int) $link->slot > BrandPartnerLinkService::PRIMARY_SLOT)
            ->sortBy('slot')
            ->map(static fn ($link): array => [
                'professional_id' => (string) $link->brand_professional_id,
            ])
            ->values()
            ->all();

        if ($settings === $originalSettings) {
            return false;
        }

        $site->settings = $settings;
        $site->save();

        return true;
    }

    private function settingsStillReferenceBrand(Site $site, string $brandProfessionalId): bool
    {
        $settings = is_array($site->settings) ? $site->settings : [];
        $primaryId = trim((string) (
            $settings['brand_partner']['professional_id']
            ?? $settings['brand_partner']['professionalId']
            ?? ''
        ));

        if ($primaryId === $brandProfessionalId) {
            return true;
        }

        $additional = $settings['additional_brand_partners'] ?? $settings['additionalBrandPartners'] ?? [];
        if (! is_array($additional)) {
            return false;
        }

        foreach ($additional as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entryId = trim((string) ($entry['professional_id'] ?? $entry['professionalId'] ?? ''));
            if ($entryId === $brandProfessionalId) {
                return true;
            }
        }

        return false;
    }

    private function invalidateAffiliateCaches(Site $site): void
    {
        $site->loadMissing('professional');
        $professional = $site->professional;
        if (! $professional) {
            return;
        }

        app(ProfessionalCacheService::class)->invalidateProfessional($professional);
    }
}
