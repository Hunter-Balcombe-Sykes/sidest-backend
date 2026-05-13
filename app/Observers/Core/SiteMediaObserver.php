<?php

namespace App\Observers\Core;

use App\Models\Core\Site\SiteMedia;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

// V2: Re-evaluates section visibility when media rows are saved, deleted, or restored.
// Currently handles gallery images and documents — each maps to a distinct section
// block type; mapping is defined in poolToBlockType().
class SiteMediaObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function saved(SiteMedia $media): void
    {
        $this->reevaluateIfRelevant($media);
    }

    public function deleted(SiteMedia $media): void
    {
        $this->reevaluateIfRelevant($media);
    }

    public function restored(SiteMedia $media): void
    {
        $this->reevaluateIfRelevant($media);
    }

    private function reevaluateIfRelevant(SiteMedia $media): void
    {
        $blockType = $this->poolToBlockType($media->pool);
        if ($blockType === null) {
            return;
        }

        try {
            $site = $media->site;
            if (! $site || ! $site->professional_id) {
                return;
            }

            $this->visibilityService->reevaluateEnabled(
                (string) $site->professional_id,
                (string) $media->site_id,
                $blockType
            );
        } catch (\Throwable $e) {
            Log::warning('Section visibility reevaluation failed on SiteMedia event', $this->logContext(__METHOD__, [
                'site_media_id' => $media->id,
                'site_id' => $media->site_id,
                'professional_id' => $site->professional_id,
                'pool' => $media->pool,
                'block_type' => $blockType,
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Map a site_media pool to the section block_type it feeds. Returns null
     * for pools that don't drive a section (brand gallery, design, product).
     */
    private function poolToBlockType(?string $pool): ?string
    {
        return match ($pool) {
            SiteMedia::POOL_GALLERY => 'gallery',
            SiteMedia::POOL_DOCUMENTS => 'documents',
            default => null,
        };
    }
}
