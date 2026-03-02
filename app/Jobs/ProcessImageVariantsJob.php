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
        $disk = Storage::disk((string) config('comet.media_disk', 'media'));

        if (!$disk->exists($this->originalPath)) {
            Log::warning('ProcessImageVariantsJob: original not found on disk.', [
                'path' => $this->originalPath,
            ]);
            return;
        }

        // Download to a local temp file so GD can read it.
        $localTmp = tempnam(sys_get_temp_dir(), 'comet_orig_');
        file_put_contents($localTmp, $disk->get($this->originalPath));

        try {
            $service->processVariants(
                originalTmpPath: $localTmp,
                imageId: $this->imageId,
                basePath: $this->basePath,
            );
        } finally {
            @unlink($localTmp);
        }

        Log::info('ProcessImageVariantsJob: variants generated.', [
            'image_id' => $this->imageId,
        ]);
    }
}
