<?php

namespace App\Observers;

use App\Models\Core\Site\Block;
use App\Services\Cache\SiteCacheService;

class BlockObserver
{
    public bool $afterCommit = true;
    public function __construct(
        private SiteCacheService $siteCache
    ) {}

    public function created(Block $block): void
    {
        if ($block->site) {
            $this->siteCache->invalidateSite($block->site);
        }
    }

    public function updated(Block $block): void
    {
        if ($block->site) {
            $this->siteCache->invalidateSite($block->site);
        }
    }

    public function deleted(Block $block): void
    {
        if ($block->site) {
            $this->siteCache->invalidateSite($block->site);
        }
    }
}
