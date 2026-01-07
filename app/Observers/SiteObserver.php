<?php

namespace App\Observers;

use App\Jobs\WarmPublicSiteCacheJob;
use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheService;

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
            WarmPublicSiteCacheJob::dispatch(strtolower($site->subdomain))->afterCommit();
        }
    }

    public function deleted(Site $site): void
    {
        $this->siteCache->invalidateSite($site);
    }
}
