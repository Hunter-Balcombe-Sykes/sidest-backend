<?php

namespace App\Http\Controllers\Api\Professional\Uploads;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Uploads\ReorderPoolImagesRequest;
use App\Http\Requests\Api\Professional\Uploads\UploadBrandFontRequest;
use App\Http\Requests\Api\Professional\Uploads\UploadBrandLogoRequest;
use App\Http\Requests\Api\Professional\Uploads\UploadBrandPlaceholderImageRequest;
use App\Http\Requests\Api\Professional\Uploads\UploadImageRequest;
use App\Jobs\DeleteMediaArtifactsJob;
use App\Jobs\ProcessImageVariantsJob;
use App\Jobs\ProcessVideoVariantsJob;
use App\Models\Core\Site\SiteImage;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProfessionalUploadController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly ImageVariantService $mediaService,
    ) {}

    /**
     * Upload an image or video to a pool (gallery or content).
     *
     * Accepts exactly one of: `image` (JPEG/PNG/WebP) or `video` (MP4/MOV/WebM).
     * Returns immediately; processing runs async on the appropriate queue.
     *
     * POST /api/uploads
     *   { pool: gallery|content, image?: <file>, video?: <file>, alt_text?: string }
     */
    public function upload(UploadImageRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $pool       = $request->validated('pool');
        $isVideo    = $request->hasFile('video');
        $file       = $isVideo ? $request->file('video') : $request->file('image');
        $mediaType  = $isVideo ? SiteImage::MEDIA_TYPE_VIDEO : SiteImage::MEDIA_TYPE_IMAGE;

        Log::info('Media upload started', [
            'pro_id'       => $pro->id,
            'site_id'      => $site->id,
            'pool'         => $pool,
            'media_type'   => $mediaType,
            'file_size_kb' => $file->getSize() / 1024,
        ]);

        // Pool limit is shared across media types (images + videos count toward the same cap).
        $maxItems = (int) config("comet.image_pools.{$pool}.max", 5);

        $activeCount = SiteImage::query()
            ->where('site_id', $site->id)
            ->where('pool', $pool)
            ->where('is_active', true)
            ->count();

        if ($activeCount >= $maxItems) {
            return $this->error(
                ucfirst($pool) . " media limit reached (max {$maxItems}).", 422
            );
        }

        // --- Create SiteImage row (with advisory lock for race safety) ---
        $media = DB::transaction(function () use ($site, $pool, $maxItems, $request, $mediaType, $file) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }

            $siteImages = SiteImage::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->get(['id', 'pool', 'sort_order', 'is_active']);

            $activeCount = $siteImages
                ->where('pool', $pool)
                ->where('is_active', true)
                ->count();

            if ($activeCount >= $maxItems) {
                abort(422, ucfirst($pool) . " media limit reached (max {$maxItems}).");
            }

            $maxSort = $siteImages->max('sort_order');

            $media = SiteImage::create([
                'site_id'             => $site->id,
                'pool'                => $pool,
                'path'                => '',
                'alt_text'            => $request->validated('alt_text'),
                'sort_order'          => is_null($maxSort) ? 0 : ((int) $maxSort + 1),
                'is_active'           => true,
                'media_type'          => $mediaType,
                'processing_state'    => SiteImage::PROCESSING_STATE_PENDING,
                'original_mime'       => $file->getMimeType(),
                'original_size_bytes' => $file->getSize(),
            ]);

            Log::info('SiteImage row created', ['media_id' => $media->id, 'media_type' => $mediaType]);

            return $media;
        });

        // --- Store original on media disk ---
        $basePath = $isVideo
            ? "videos/{$pro->id}/{$media->id}"
            : "images/{$pro->id}/{$media->id}";

        try {
            $mediaDisk = $this->mediaService->resolvedDiskName();

            Log::info('Storing original to media disk', [
                'media_id'   => $media->id,
                'base_path'  => $basePath,
                'media_disk' => $mediaDisk,
            ]);

            if ($isVideo) {
                // Stream large video files to avoid loading full content into memory.
                $ext    = $file->getClientOriginalExtension() ?: 'mp4';
                $hash   = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
                $path   = "{$basePath}/original_{$hash}.{$ext}";
                $stream = fopen($file->getRealPath(), 'rb');
                Storage::disk($mediaDisk)->put($path, $stream, 'public');
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $originalPath = $path;
            } else {
                $originalPath = $this->mediaService->storeOriginal($file, $basePath);
            }

            Log::info('Original stored successfully', ['media_id' => $media->id, 'path' => $originalPath]);
        } catch (\Exception $e) {
            Log::error('Failed to store original', [
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
            ]);
            $media->delete();
            return $this->error('Failed to store file: ' . $e->getMessage(), 500);
        }

        $media->update(['path' => $originalPath]);

        // --- Dispatch processing job ---
        if ($isVideo) {
            $this->dispatchVideoJob($media->id, $originalPath, $basePath);
        } else {
            $this->dispatchImageJob($media->id, $originalPath, $basePath);
        }

        app(SiteCacheService::class)->invalidateSite($site);

        // Refresh model state (sync mode may have updated processing_state to 'ready').
        // Eager-load the appropriate relation so buildMediaPayload avoids a lazy-load hit.
        $media->refresh();
        if ($media->media_type === SiteImage::MEDIA_TYPE_IMAGE) {
            $media->load('variants');
        } elseif ($media->media_type === SiteImage::MEDIA_TYPE_VIDEO) {
            $media->load('mediaVariants');
        }
        $payload = $this->buildMediaPayload($media, includeVariants: true);

        return $this->success($payload, 201);
    }

    /**
     * List media for the authenticated professional.
     *
     * GET /api/images
     *   ?pool=gallery|content          optional pool filter
     *   ?media_type=image|video|all    default: image (backward-compatible)
     *   ?ids[]=uuid,...                optional: return only specific media items (for polling)
     */
    public function index(): JsonResponse
    {
        $pro = $this->currentProfessional(request());
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $rawMediaType = strtolower(trim((string) request()->input('media_type', 'image')));
        $mediaTypeFilter = in_array($rawMediaType, ['image', 'video', 'all'], true) ? $rawMediaType : 'image';

        $query = SiteImage::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderBy('pool')
            ->orderBy('sort_order')
            ->orderBy('created_at');

        if ($mediaTypeFilter !== 'all') {
            $query->where('media_type', $mediaTypeFilter);
        }

        if (request()->has('pool')) {
            $pool = strtolower(trim(request()->input('pool')));
            if (in_array($pool, ['gallery', 'content'], true)) {
                $query->where('pool', $pool);
            }
        }

        // Efficient polling: return only the requested IDs.
        if (request()->has('ids')) {
            $ids = array_filter((array) request()->input('ids'), fn ($id) => is_string($id) && Str::isUuid($id));
            if (! empty($ids)) {
                $query->whereIn('id', array_values($ids));
            }
        }

        // Eager-load the appropriate variants based on media type filter.
        if ($mediaTypeFilter === 'image') {
            $query->with('variants');
        } elseif ($mediaTypeFilter === 'video') {
            $query->with('mediaVariants');
        } else {
            $query->with(['variants', 'mediaVariants']);
        }

        $items = $query->get()->map(fn (SiteImage $item) => $this->buildMediaPayload($item, includeVariants: true));

        return $this->success([
            'images' => $items,
            'limits' => [
                'gallery' => config('comet.image_pools.gallery.max', 5),
                'content' => config('comet.image_pools.content.max', 5),
            ],
        ]);
    }

    /**
     * Reorder active media for a specific pool.
     *
     * POST /api/images/reorder
     *   { pool: gallery|content, media_type?: image|video, ids: [uuid, ...] }
     *
     * Scope is pool + media_type (defaults to image for backward compatibility).
     */
    public function reorder(ReorderPoolImagesRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $pool      = $request->validated('pool');
        $mediaType = $request->validated('media_type') ?? SiteImage::MEDIA_TYPE_IMAGE;
        $ids       = array_values(array_unique($request->validated('ids') ?? []));

        DB::transaction(function () use ($site, $pool, $mediaType, $ids) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }

            $siteImages = SiteImage::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->get(['id', 'pool', 'media_type', 'sort_order', 'is_active']);

            $targetImages = $siteImages
                ->where('is_active', true)
                ->where('pool', $pool)
                ->where('media_type', $mediaType)
                ->values();

            if ($targetImages->isEmpty()) {
                abort(422, 'No active media found in this pool.');
            }

            $targetIds = $targetImages->pluck('id')->all();
            $targetSet = array_flip($targetIds);

            foreach ($ids as $id) {
                if (! isset($targetSet[$id])) {
                    abort(403, 'One or more items do not belong to your site.');
                }
            }

            $remainingTargetIds = array_values(array_diff($targetIds, $ids));
            $reorderedTargetIds = array_merge($ids, $remainingTargetIds);

            $finalIds        = $siteImages->pluck('id')->all();
            $targetPositions = [];

            foreach ($siteImages as $index => $image) {
                if ($image->is_active && $image->pool === $pool && $image->media_type === $mediaType) {
                    $targetPositions[] = $index;
                }
            }

            foreach ($targetPositions as $index => $position) {
                $finalIds[$position] = $reorderedTargetIds[$index];
            }

            $offset = $siteImages->count() + 1000;

            foreach ($finalIds as $index => $id) {
                SiteImage::query()
                    ->where('site_id', $site->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $offset + $index]);
            }

            foreach ($finalIds as $index => $id) {
                SiteImage::query()
                    ->where('site_id', $site->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $index]);
            }
        });

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['ok' => true]);
    }

    /**
     * Delete a media item and all its artifacts.
     *
     * Images are cleaned up synchronously (small number of files).
     * Videos are cleaned up asynchronously via DeleteMediaArtifactsJob (many HLS segments).
     *
     * DELETE /api/images/{image}
     */
    public function destroy(SiteImage $image): JsonResponse
    {
        $pro = $this->currentProfessional(request());
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        abort_unless($image->site_id === $site->id, 404);

        if ($image->media_type === SiteImage::MEDIA_TYPE_VIDEO) {
            // Dispatch async cleanup – video has many HLS segment files.
            DeleteMediaArtifactsJob::dispatch($image->id, $image->path, $image->pool);
        } else {
            // Synchronous cleanup for images (only 2–3 variant files).
            $this->mediaService->deleteVariants($image->id, $image->path);
        }

        $image->delete();

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['deleted' => true]);
    }

    /* ------------------------------------------------------------------ */
    /*  Brand-only upload endpoints (unchanged)                            */
    /* ------------------------------------------------------------------ */

    /**
     * POST /api/uploads/brand-font  { font: <file.woff2> }
     */
    public function uploadBrandFont(UploadBrandFontRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        if (($pro->professional_type ?? null) !== 'brand') {
            return $this->error('Brand font uploads are only available for brand accounts.', 403);
        }

        $file = $request->file('font');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $originalName = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBaseName = Str::slug($originalName !== '' ? $originalName : 'brand-font');
        $hash = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
        $path = "fonts/{$pro->id}/design/{$safeBaseName}_{$hash}.{$extension}";
        $mediaDisk = $this->mediaService->resolvedDiskName();
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($mediaDisk);

        $disk->put($path, file_get_contents($file->getRealPath()), 'public');

        return $this->success([
            'path' => $path,
            'url' => $disk->url($path),
            'name' => $file->getClientOriginalName(),
            'disk' => $mediaDisk,
            'site_id' => $site->id,
        ], 201);
    }

    /**
     * POST /api/uploads/brand-logo  { logo: <image> }
     */
    public function uploadBrandLogo(UploadBrandLogoRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        if (($pro->professional_type ?? null) !== 'brand') {
            return $this->error('Brand logo uploads are only available for brand accounts.', 403);
        }

        $file = $request->file('logo');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $originalName = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBaseName = Str::slug($originalName !== '' ? $originalName : 'brand-logo');
        $hash = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
        $path = "images/{$pro->id}/design/logo/{$safeBaseName}_{$hash}.{$extension}";
        $mediaDisk = $this->mediaService->resolvedDiskName();
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($mediaDisk);

        $disk->put($path, file_get_contents($file->getRealPath()), 'public');

        return $this->success([
            'path' => $path,
            'url' => $disk->url($path),
            'name' => $file->getClientOriginalName(),
            'disk' => $mediaDisk,
            'site_id' => $site->id,
        ], 201);
    }

    /**
     * POST /api/uploads/brand-placeholder-image  { image: <image> }
     */
    public function uploadBrandPlaceholderImage(UploadBrandPlaceholderImageRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        if (($pro->professional_type ?? null) !== 'brand') {
            return $this->error('Placeholder image uploads are only available for brand accounts.', 403);
        }

        $file = $request->file('image');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $originalName = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBaseName = Str::slug($originalName !== '' ? $originalName : 'placeholder-image');
        $hash = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
        $path = "images/{$pro->id}/design/placeholders/{$safeBaseName}_{$hash}.{$extension}";
        $mediaDisk = $this->mediaService->resolvedDiskName();
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($mediaDisk);

        $disk->put($path, file_get_contents($file->getRealPath()), 'public');

        return $this->success([
            'path' => $path,
            'url' => $disk->url($path),
            'name' => $file->getClientOriginalName(),
            'disk' => $mediaDisk,
            'site_id' => $site->id,
        ], 201);
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function dispatchImageJob(string $imageId, string $originalPath, string $basePath): void
    {
        $queueConnection  = (string) config('queue.default', 'sync');
        $processInline    = in_array(app()->environment(), ['local', 'testing'], true)
            || $queueConnection === 'sync';

        if ($processInline) {
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $e) {
                Log::error('Inline image variant processing failed.', [
                    'image_id' => $imageId, 'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        try {
            ProcessImageVariantsJob::dispatch(
                originalPath: $originalPath,
                imageId: $imageId,
                basePath: $basePath,
            );
        } catch (Throwable $e) {
            Log::error('Queue dispatch failed for image; trying synchronous fallback.', [
                'image_id' => $imageId, 'error' => $e->getMessage(),
            ]);
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $syncError) {
                Log::error('Synchronous image variant processing also failed.', [
                    'image_id' => $imageId, 'error' => $syncError->getMessage(),
                ]);
            }
        }
    }

    private function dispatchVideoJob(string $mediaId, string $originalPath, string $basePath): void
    {
        $queueDefault = (string) config('queue.default', 'sync');
        $processInline   = in_array(app()->environment(), ['local', 'testing'], true)
            || $queueDefault === 'sync';

        if ($processInline) {
            try {
                ProcessVideoVariantsJob::dispatchSync(
                    mediaId: $mediaId,
                    originalPath: $originalPath,
                    basePath: $basePath,
                );
            } catch (Throwable $e) {
                Log::error('Inline video variant processing failed.', [
                    'media_id' => $mediaId, 'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        try {
            // Connection and queue are already set in the job constructor.
            // Do not override via PendingDispatch to avoid redundant/conflicting config reads.
            ProcessVideoVariantsJob::dispatch(
                mediaId: $mediaId,
                originalPath: $originalPath,
                basePath: $basePath,
            );
        } catch (Throwable $e) {
            Log::error('Video queue dispatch failed.', [
                'media_id' => $mediaId, 'error' => $e->getMessage(),
            ]);
            // Do NOT fall back to sync for video – transcoding on an HTTP worker
            // would time out and kill the process. Leave as pending for retry.
        }
    }

    /**
     * Build a media item payload array suitable for API responses.
     *
     * @param  bool  $includeVariants  Whether to include resolved variant/stream maps.
     * @return array<string, mixed>
     */
    private function buildMediaPayload(SiteImage $media, bool $includeVariants = false): array
    {
        $isVideo     = $media->media_type === SiteImage::MEDIA_TYPE_VIDEO;
        $isReady     = $media->processing_state === SiteImage::PROCESSING_STATE_READY;
        $isProcessing = $media->processing_state === SiteImage::PROCESSING_STATE_PENDING
            || $media->processing_state === SiteImage::PROCESSING_STATE_PROCESSING;

        $payload = [
            'id'               => $media->id,
            'pool'             => $media->pool,
            'alt_text'         => $media->alt_text,
            'sort_order'       => $media->sort_order,
            'media_type'       => $media->media_type,
            'processing_state' => $media->processing_state,
            'processing'       => $isProcessing, // backward-compat boolean
            'processing_error' => $media->processing_error,
            'created_at'       => $media->created_at,
            'updated_at'       => $media->updated_at,
        ];

        if ($isVideo) {
            $payload['duration_ms'] = $media->duration_ms;
            $payload['poster']      = null;
        }

        if (! $includeVariants) {
            return $payload;
        }

        if ($isVideo) {
            if ($isReady) {
                $mvList   = $media->relationLoaded('mediaVariants')
                    ? $media->mediaVariants
                    : $media->mediaVariants()->get();

                $variants = [];
                $streams  = [];
                $poster   = null;

                foreach ($mvList as $mv) {
                    if ($mv->artifact_type === 'mp4') {
                        $variants[$mv->variant_key] = $mv->url;
                    } elseif ($mv->artifact_type === 'hls_playlist') {
                        $streams[$mv->variant_key] = $mv->url;
                    } elseif ($mv->artifact_type === 'poster') {
                        $poster = $mv->url;
                    }
                }

                $payload['variants'] = $variants;
                $payload['streams']  = $streams;
                $payload['poster']   = $poster;
            } else {
                $payload['variants'] = [];
                $payload['streams']  = [];
                $payload['poster']   = null;
            }
        } else {
            $payload['variants'] = $isReady ? $media->variantUrls() : [];
        }

        return $payload;
    }
}
