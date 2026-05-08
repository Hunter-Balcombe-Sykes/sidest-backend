<?php

namespace App\Observers\Core;

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates site cache and dispatches cache warm job on publish.
// Also syncs Cloudflare KV routing table on subdomain change, cascading to
// all connected affiliates when this site belongs to a brand.
class SiteObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteCacheService $siteCache,
    ) {}

    public function saved(Site $site): void
    {
        try {
            $this->siteCache->invalidateSite($site);
        } catch (\Throwable $e) {
            Log::warning('Site cache invalidation failed on save', [
                'site_id' => $site->id,
                'subdomain' => $site->subdomain,
                'message' => $e->getMessage(),
            ]);
        }

        // Warm cache asynchronously if published
        if ($site->is_published) {
            try {
                WarmPublicSiteCacheJob::dispatch(strtolower($site->subdomain))->afterCommit();
            } catch (\Throwable $e) {
                // Never fail the write path because cache warming failed.
                Log::warning('WarmPublicSiteCacheJob dispatch failed', [
                    'site_id' => $site->id,
                    'subdomain' => $site->subdomain,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Sync KV when site is first created or subdomain changes.
        // Also cascade to all affiliates when this site belongs to a brand,
        // since their site_url (brand.partna.au/affiliate) depends on the brand subdomain.
        if ($site->wasRecentlyCreated || $site->wasChanged('subdomain')) {
            $professionalId = (string) ($site->professional_id ?? '');

            try {
                SyncSubdomainToKvJob::dispatch($professionalId);
            } catch (\Throwable $e) {
                Log::warning('SiteObserver: KV sync dispatch failed on subdomain change', [
                    'site_id' => $site->id,
                    'professional_id' => $professionalId,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $this->cascadeAffiliateKvSync($professionalId);
            } catch (\Throwable $e) {
                Log::warning('SiteObserver: KV affiliate cascade failed', [
                    'site_id' => $site->id,
                    'professional_id' => $professionalId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function deleted(Site $site): void
    {
        try {
            $this->siteCache->invalidateSite($site);
        } catch (\Throwable $e) {
            Log::warning('Site cache invalidation failed on delete', [
                'site_id' => $site->id,
                'subdomain' => $site->subdomain,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // Dispatch KV sync for every affiliate connected to this brand professional.
    // No-op if the professional has no affiliate connections (i.e., they're not a brand).
    private function cascadeAffiliateKvSync(string $brandProfessionalId): void
    {
        if ($brandProfessionalId === '') {
            return;
        }

        BrandPartnerLink::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('affiliate_professional_id')
            ->each(function (string $affiliateId): void {
                SyncSubdomainToKvJob::dispatch($affiliateId);
            });
    }
}
