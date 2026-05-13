<?php

namespace App\Observers\Core;

use App\Models\Core\Site\Block;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates site cache when any block (link, section) is created, updated, or deleted.
class BlockObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private SiteCacheService $siteCache
    ) {}

    public function created(Block $block): void
    {
        if ($block->site) {
            try {
                $this->siteCache->invalidateSite($block->site);
            } catch (\Throwable $e) {
                Log::warning('Site cache invalidation failed on block create', $this->logContext(__METHOD__, [
                    'block_id' => $block->id,
                    'site_id' => $block->site->id,
                    'message' => $e->getMessage(),
                ]));
            }
        }
    }

    public function updated(Block $block): void
    {
        if ($block->site) {
            try {
                $this->siteCache->invalidateSite($block->site);
            } catch (\Throwable $e) {
                Log::warning('Site cache invalidation failed on block update', $this->logContext(__METHOD__, [
                    'block_id' => $block->id,
                    'site_id' => $block->site->id,
                    'message' => $e->getMessage(),
                ]));
            }
        }
    }

    public function deleted(Block $block): void
    {
        if ($block->site) {
            try {
                $this->siteCache->invalidateSite($block->site);
            } catch (\Throwable $e) {
                Log::warning('Site cache invalidation failed on block delete', $this->logContext(__METHOD__, [
                    'block_id' => $block->id,
                    'site_id' => $block->site->id,
                    'message' => $e->getMessage(),
                ]));
            }
        }
    }
}
