<?php

namespace App\Observers\Core;

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheService;
use App\Services\Legal\ProfessionalLegalContentService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates site cache, refreshes legal templates, and dispatches cache warm job on publish.
class SiteObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteCacheService $siteCache,
        private readonly ProfessionalLegalContentService $legalContentService
    ) {}

    public function saved(Site $site): void
    {
        try {
            $site->loadMissing('professional');
            if ($site->professional) {
                $this->legalContentService->refreshGenerated($site->professional, $site);
            }
        } catch (\Throwable $e) {
            Log::warning('Legal template regeneration failed on site save', [
                'site_id' => $site->id,
                'subdomain' => $site->subdomain,
                'message' => $e->getMessage(),
            ]);
        }

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
}
