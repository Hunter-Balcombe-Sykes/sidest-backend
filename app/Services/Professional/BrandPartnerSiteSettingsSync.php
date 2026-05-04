<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Cache\ProfessionalCacheService;

// Keeps site.settings.brand_partner and .additional_brand_partners in sync
// with the affiliate's current brand_partner_links, and invalidates
// professional caches. Extracted from BrandPartnerController so all three
// disconnect paths (staff, brand, affiliate) use identical logic.
class BrandPartnerSiteSettingsSync
{
    public function __construct(
        private readonly BrandPartnerLinkService $links,
        private readonly ProfessionalCacheService $cache,
    ) {}

    /**
     * Rebuild brand_partner settings on the site and save if changed.
     * Returns true if settings were mutated (and saved).
     */
    public function sync(Site $site, string $affiliateProfessionalId): bool
    {
        $changed = $this->syncWithoutPersist($site, $affiliateProfessionalId);
        if ($changed) {
            $site->save();
        }

        return $changed;
    }

    /**
     * Mutate in-memory settings without persisting. Used by tests and by
     * the lifecycle service which persists within a transaction boundary.
     */
    public function syncWithoutPersist(Site $site, string $affiliateProfessionalId): bool
    {
        $links = $this->links->getLinksForAffiliate($affiliateProfessionalId);
        // Read raw attribute — handles both JSON string (from DB) and array (in-memory / test).
        $rawAttr = $site->getAttributes()['settings'] ?? null;
        $settings = is_array($rawAttr)
            ? $rawAttr
            : (is_string($rawAttr) ? (json_decode($rawAttr, true) ?? []) : []);
        $original = $settings;

        $brandPartner = is_array($settings['brand_partner'] ?? null)
            ? $settings['brand_partner']
            : [];

        $primary = $links->firstWhere('slot', BrandPartnerLinkService::PRIMARY_SLOT);
        if ($primary) {
            $brandPartner['professional_id'] = (string) $primary->brand_professional_id;

            // Sync the brand's storefront URL so affiliates can build correct share links.
            // Uses the brand's custom domain when fully provisioned, else platform subdomain.
            $brand = Professional::query()->with('site')->find($primary->brand_professional_id);
            if ($brand && $brand->site) {
                $subdomain = (string) $brand->site->subdomain;
                $storeSettings = BrandStoreSettings::query()
                    ->where('professional_id', $brand->id)
                    ->first();

                $storefrontBaseUrl = $storeSettings
                    ? $storeSettings->storefrontBaseUrl($subdomain)
                    : 'https://'.$subdomain.'.sidest.co';

                $brandPartner['subdomain'] = $subdomain;
                $brandPartner['storefront_base_url'] = $storefrontBaseUrl;
            }
        } else {
            unset(
                $brandPartner['professional_id'],
                $brandPartner['professionalId'],
                $brandPartner['subdomain'],
                $brandPartner['storefront_base_url'],
            );
        }

        $settings['brand_partner'] = $brandPartner;
        $settings['additional_brand_partners'] = $links
            ->filter(static fn ($l): bool => (int) $l->slot > BrandPartnerLinkService::PRIMARY_SLOT)
            ->sortBy('slot')
            ->map(static fn ($l): array => ['professional_id' => (string) $l->brand_professional_id])
            ->values()
            ->all();

        if ($settings === $original) {
            return false;
        }

        $site->settings = $settings;

        return true;
    }

    public function settingsStillReferenceBrand(Site $site, string $brandProfessionalId): bool
    {
        $rawAttr = $site->getAttributes()['settings'] ?? null;
        $settings = is_array($rawAttr)
            ? $rawAttr
            : (is_string($rawAttr) ? (json_decode($rawAttr, true) ?? []) : []);
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

    /** Invalidate affiliate professional cache. */
    public function invalidateAffiliateCaches(Site $site): void
    {
        $site->loadMissing('professional');
        $professional = $site->professional;
        if (! $professional) {
            return;
        }
        $this->cache->invalidateProfessional($professional);
    }
}
