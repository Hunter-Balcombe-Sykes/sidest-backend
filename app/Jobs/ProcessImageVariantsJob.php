<?php

namespace App\Jobs;

use App\Services\Media\ImageVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates all WebP variants for a freshly uploaded image.
 *
 * Dispatched immediately after the original file is stored on the media
 * disk, so the upload endpoint returns quickly while heavy GD processing
 * runs async on the "images" queue.
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
        Log::info('ProcessImageVariantsJob: starting variant processing', [
            'image_id' => $this->imageId,
            'original_path' => $this->originalPath,
        ]);

        $diskName = $service->resolvedDiskName();
        $disk = Storage::disk($diskName);

        if (!$disk->exists($this->originalPath)) {
            Log::error('ProcessImageVariantsJob: original file not found on disk.', [
                'path' => $this->originalPath,
                'image_id' => $this->imageId,
                'disk' => $diskName,
            ]);
            $this->fail(new \Exception('Original file not found on media disk.'));
            return;
        }

        try {
            // Download to a local temp file so GD can read it.
            $localTmp = tempnam(sys_get_temp_dir(), 'comet_orig_');
            if (!$localTmp) {
                throw new \RuntimeException('Failed to create temporary file.');
            }

            $content = $disk->get($this->originalPath);
            if (!file_put_contents($localTmp, $content)) {
                throw new \RuntimeException('Failed to write original to temp file.');
            }

            $service->processVariants(
                originalTmpPath: $localTmp,
                imageId: $this->imageId,
                basePath: $this->basePath,
            );

            Log::info('ProcessImageVariantsJob: variants generated successfully.', [
                'image_id' => $this->imageId,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessImageVariantsJob: variant generation failed.', [
                'image_id' => $this->imageId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            $this->fail($e);
        } finally {
            if (isset($localTmp) && file_exists($localTmp)) {
                @unlink($localTmp);
            }
        }
    }
}
