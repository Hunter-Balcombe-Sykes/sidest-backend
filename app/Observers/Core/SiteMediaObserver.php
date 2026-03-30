<?php

namespace App\Observers\Core;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

class SiteMediaObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function saved(SiteMedia $media): void
    {
        if ($media->pool !== SiteMedia::POOL_GALLERY) {
            return;
        }
        $this->reevaluateGallery($media);
    }

    public function deleted(SiteMedia $media): void
    {
        if ($media->pool !== SiteMedia::POOL_GALLERY) {
            return;
        }
        $this->reevaluateGallery($media);
    }

    public function restored(SiteMedia $media): void
    {
        if ($media->pool !== SiteMedia::POOL_GALLERY) {
            return;
        }
        $this->reevaluateGallery($media);
    }

    private function reevaluateGallery(SiteMedia $media): void
    {
        try {
            $site = Site::query()->find($media->site_id);
            if (! $site || ! $site->professional_id) {
                return;
            }

            $this->visibilityService->reevaluateEnabled(
                (string) $site->professional_id,
                (string) $media->site_id,
                'gallery'
            );
        } catch (\Throwable $e) {
            Log::warning('Gallery section visibility reevaluation failed', [
                'site_media_id' => $media->id,
                'site_id'       => $media->site_id,
                'message'       => $e->getMessage(),
            ]);
        }
    }
}
