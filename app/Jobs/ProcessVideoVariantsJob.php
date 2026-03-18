<?php

namespace App\Jobs;

use App\Models\Core\Site\SiteImage;
use App\Services\Media\VideoVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Transcodes a video upload into MP4 + HLS variants and a poster image.
 *
 * Dispatched onto the dedicated "videos" queue (redis_video connection)
 * so transcoding never contends with image processing or HTTP workers.
 *
 * State transitions:
 *   pending → processing (job starts)
 *   processing → ready   (all artifacts stored)
 *   processing → failed  (unrecoverable error after all retries)
 *
 * Worker invocation:
 *   php artisan queue:work redis_video --queue=videos --timeout=3600
 */
class ProcessVideoVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    /**
     * @param  string  $mediaId       UUID of the SiteImage row.
     * @param  string  $originalPath  Storage path of the uploaded original.
     * @param  string  $basePath      Directory prefix under media disk (videos/{proId}/{mediaId}).
     */
    public function __construct(
        public readonly string $mediaId,
        public readonly string $originalPath,
        public readonly string $basePath,
    ) {
        $this->onConnection((string) config('comet.video_queue.connection', 'redis_video'));
        $this->onQueue((string) config('comet.video_queue.name', 'videos'));
    }

    public function handle(VideoVariantService $service): void
    {
        Log::info('ProcessVideoVariantsJob: starting', [
            'media_id'      => $this->mediaId,
            'original_path' => $this->originalPath,
        ]);

        SiteImage::query()
            ->where('id', $this->mediaId)
            ->update(['processing_state' => SiteImage::PROCESSING_STATE_PROCESSING]);

        $diskName = $service->resolvedDiskName();
        $disk     = Storage::disk($diskName);

        if (! $disk->exists($this->originalPath)) {
            $this->markFailed('Original video file not found on media disk.');
            $this->fail(new \Exception('Original video file not found on media disk.'));
            return;
        }

        $localTmp = null;

        try {
            // Stream the original to a local temp file for FFmpeg processing.
            $ext      = pathinfo($this->originalPath, PATHINFO_EXTENSION) ?: 'mp4';
            $localTmp = tempnam(sys_get_temp_dir(), 'comet_vid_') . '.' . $ext;

            $stream = $disk->readStream($this->originalPath);
            if (! $stream) {
                throw new \RuntimeException('Failed to open read stream for original video.');
            }

            $dest = fopen($localTmp, 'wb');
            if (! $dest) {
                fclose($stream);
                throw new \RuntimeException('Failed to open local temp file for writing.');
            }

            stream_copy_to_stream($stream, $dest);
            fclose($stream);
            fclose($dest);

            $service->processVariants(
                localOriginalPath: $localTmp,
                mediaId: $this->mediaId,
                basePath: $this->basePath,
            );

            Log::info('ProcessVideoVariantsJob: completed.', ['media_id' => $this->mediaId]);
        } catch (\Throwable $e) {
            Log::error('ProcessVideoVariantsJob: processing failed.', [
                'media_id'  => $this->mediaId,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            if ($this->attempts() >= $this->tries) {
                $this->markFailed($e->getMessage());
            }

            $this->fail($e);
        } finally {
            if (isset($localTmp) && file_exists($localTmp)) {
                @unlink($localTmp);
            }
        }
    }

    private function markFailed(string $reason): void
    {
        SiteImage::query()
            ->where('id', $this->mediaId)
            ->update([
                'processing_state' => SiteImage::PROCESSING_STATE_FAILED,
                'processing_error' => $reason,
            ]);
    }
}
