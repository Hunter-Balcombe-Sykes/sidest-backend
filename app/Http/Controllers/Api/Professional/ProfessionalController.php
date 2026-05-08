<?php

namespace App\Http\Controllers\Api\Professional;

use App\Enums\BrandStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\ProfessionalShowRequest;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Site\Block;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\DB;

// V2: Returns authenticated professional's full profile with site, services, and blocks. Dashboard entry point.
class ProfessionalController extends ApiController
{
    /** @return array<int, string> */
    private function professionalOnlySectionTypes(): array
    {
        return config('partna.professional_only_section_types', []);
    }

    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function show(ProfessionalShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');

        $pro = $this->currentProfessional($request);
        // squareIntegration eager-loads inside the AUTH-1 cached Professional
        // (60s SWR), so no extra round-trip is needed here on cache hits.

        $cache = app(ProfessionalCacheService::class);

        $siteSettings = [];
        $primaryBrandStatus = null;
        $primaryBrandName = null;
        $primaryAffiliateSiteUrl = null;
        if ($pro->site) {
            $siteSettings = is_array($pro->site->settings) ? $pro->site->settings : [];
            $siteSettings = app(SiteCacheService::class)->hydrateTypographySettings(
                $siteSettings,
                (string) $pro->id
            );

            // Resolve primary brand partner status + name + affiliate site URL so the
            // dashboard can surface affiliate-facing banners, status dots, and the
            // canonical affiliate page URL (brand.partna.au/affiliate).
            if ($pro->professional_type !== 'brand') {
                $brandPartnerId = $siteSettings['brand_partner']['professional_id'] ?? null;
                if ($brandPartnerId) {
                    $partner = $cache->getBrandPartnerStatus((string) $brandPartnerId);
                    $primaryBrandStatus = $partner['brand_status'] ?? BrandStatus::Onboarding->value;
                    $primaryBrandName = $partner['display_name'] ?? null;
                    $primaryAffiliateSiteUrl = BrandPartnerLink::query()
                        ->where('brand_professional_id', $brandPartnerId)
                        ->where('affiliate_professional_id', $pro->id)
                        ->value('site_url');
                }
            }
        }

        $payload = [
            'professional' => new ProfessionalDashboardResource($pro),
            'site' => $pro->site ? [
                'id' => $pro->site->id,
                'subdomain' => $pro->site->subdomain,
                'is_published' => (bool) $pro->site->is_published,
                'settings' => $siteSettings,
                'storefront_base_url' => 'https://'.$pro->site->subdomain.'.'.config('partna.public_domain', 'partna.au'),
                'affiliate_page_url' => $pro->professional_type === 'brand' ? $pro->partna_url : $primaryAffiliateSiteUrl,
            ] : null,
        ];

        $services = $cache->getActiveServices($pro->id);
        $customersCount = $cache->getCustomerCount($pro->id);
        $blocks = $pro->site
            ? app(SiteCacheService::class)->getSiteLinkBlocks($pro->site->id)
            : [];

        return $this->success([
            'uid' => $uid,
            ...$payload,
            'blocks' => $blocks,
            'services' => $services,
            'customers_count' => $customersCount,
            'primary_brand_status' => $primaryBrandStatus,
            'primary_brand_name' => $primaryBrandName,
        ]);
    }

    public function update(UpdateProfessionalRequest $request)
    {
        $professional = $this->currentProfessional($request);
        $previousProfessionalType = mb_strtolower(trim((string) ($professional->professional_type ?? '')));
        DB::transaction(function () use ($professional, $request, $previousProfessionalType): void {
            $professional->fill($request->validated());
            $professional->save();

            $nextProfessionalType = mb_strtolower(trim((string) ($professional->professional_type ?? '')));
            if ($previousProfessionalType !== 'influencer' && $nextProfessionalType === 'influencer') {
                $this->disableProfessionalOnlySections($professional->id);
            }
        });

        return $this->success([
            'professional' => new ProfessionalDashboardResource($professional->fresh()),
        ]);
    }

    private function disableProfessionalOnlySections(string $professionalId): void
    {
        if ($professionalId === '') {
            return;
        }

        Block::query()
            ->where('professional_id', $professionalId)
            ->where('block_group', 'sections')
            ->whereIn('block_type', $this->professionalOnlySectionTypes())
            ->where('is_active', true)
            ->update([
                'is_active' => false,
            ]);
    }
}
