<?php

namespace App\Observers\Core;

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

class SiteObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteCacheService $siteCache
    ) {}

    public function saved(Site $site): void
    {
        $this->siteCache->invalidateSite($site);

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
        $this->siteCache->invalidateSite($site);
    }
}
