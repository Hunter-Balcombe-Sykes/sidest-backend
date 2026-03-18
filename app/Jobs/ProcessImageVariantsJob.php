<?php

namespace App\Jobs;

use App\Models\Core\Site\SiteImage;
use App\Services\Media\ImageVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generates all WebP variants for a freshly uploaded image.
 *
 * State transitions:
 *   pending → processing (job starts)
 *   processing → ready   (all variants created)
 *   processing → failed  (unrecoverable error after all retries)
 */
class ProcessImageVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  string  $originalPath  Path of the original on the media disk.
     * @param  string  $imageId       UUID of the SiteImage row.
     * @param  string  $basePath      Directory prefix for variant storage.
     */
    public function __construct(
        public readonly string $originalPath,
        public readonly string $imageId,
        public readonly string $basePath,
    ) {
        $this->onQueue('images');
    }

    public function handle(ImageVariantService $service): void
    {
        Log::info('ProcessImageVariantsJob: starting', [
            'image_id'      => $this->imageId,
            'original_path' => $this->originalPath,
        ]);

        $siteImage = SiteImage::withTrashed()->find($this->imageId);

        if (! $siteImage) {
            Log::warning('ProcessImageVariantsJob: SiteImage row no longer exists, skipping.', [
                'image_id' => $this->imageId,
            ]);
            return;
        }

        if ($siteImage->trashed()) {
            Log::info('ProcessImageVariantsJob: SiteImage row is soft-deleted, skipping.', [
                'image_id' => $this->imageId,
            ]);
            return;
        }

        SiteImage::query()
            ->where('id', $this->imageId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteImage::PROCESSING_STATE_PROCESSING,
                'processing_error' => null,
            ]);

        $diskName = $service->resolvedDiskName();
        $disk     = Storage::disk($diskName);

        if (! $disk->exists($this->originalPath)) {
            $this->markFailed('Original file not found on media disk.');
            $this->fail(new \RuntimeException('Original file not found on media disk.'));
            return;
        }

        $localTmp = null;

        try {
            $localTmp = tempnam(sys_get_temp_dir(), 'comet_orig_');
            if (! $localTmp) {
                throw new \RuntimeException('Failed to create temporary file.');
            }

            $content = $disk->get($this->originalPath);
            if (! file_put_contents($localTmp, $content)) {
                throw new \RuntimeException('Failed to write original to temp file.');
            }

            $service->processVariants(
                originalTmpPath: $localTmp,
                imageId: $this->imageId,
                basePath: $this->basePath,
            );

            SiteImage::query()
                ->where('id', $this->imageId)
                ->whereNull('deleted_at')
                ->update([
                    'processing_state' => SiteImage::PROCESSING_STATE_READY,
                    'processing_error' => null,
                ]);

            Log::info('ProcessImageVariantsJob: completed.', ['image_id' => $this->imageId]);
        } catch (Throwable $e) {
            Log::error('ProcessImageVariantsJob: variant generation failed.', [
                'image_id'  => $this->imageId,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw $e;
        } finally {
            if (isset($localTmp) && file_exists($localTmp)) {
                @unlink($localTmp);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($e->getMessage());
    }

    private function markFailed(string $reason): void
    {
        $updated = SiteImage::query()
            ->where('id', $this->imageId)
            ->whereNull('deleted_at')
            ->update([
                'processing_state' => SiteImage::PROCESSING_STATE_FAILED,
                'processing_error' => $reason,
            ]);

        if ($updated === 0) {
            $siteImage = SiteImage::withTrashed()->where('id', $this->imageId)->first();
            Log::info('ProcessImageVariantsJob: failed-state update skipped.', [
                'image_id'         => $this->imageId,
                'row_exists'       => $siteImage !== null,
                'is_soft_deleted'  => $siteImage?->trashed() ?? false,
            ]);
        }
    }
}
