<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\Professional\Brand\BrandStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BrandGalleryController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    private const POOL = SiteMedia::POOL_BRAND_GALLERY;

    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_FILE_KB = 10240; // 10 MB

    public function __construct(
        private readonly ImageVariantService $mediaService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $images = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', self::POOL)
            ->where('is_active', true)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteMedia $media) => $this->buildPayload($media));

        $max = (int) config('partna.image_pools.brand_gallery.max', 5);

        return $this->success([
            'images' => $images,
            'limit' => $max,
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $request->validate([
            'image' => [
                'required',
                'file',
                'mimetypes:'.implode(',', self::ALLOWED_MIMES),
                'max:'.self::MAX_FILE_KB,
            ],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $file = $request->file('image');
        $maxItems = (int) config('partna.image_pools.brand_gallery.max', 5);

        $media = DB::transaction(function () use ($site, $maxItems, $request, $file) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }

            $siteImages = SiteMedia::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->get(['id', 'pool', 'sort_order', 'is_active']);

            $activeCount = $siteImages
                ->where('pool', self::POOL)
                ->where('is_active', true)
                ->count();

            if ($activeCount >= $maxItems) {
                abort(422, "Brand gallery limit reached (max {$maxItems}).");
            }

            $maxSort = $siteImages->where('pool', self::POOL)->max('sort_order');

            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => self::POOL,
                'path' => '',
                'alt_text' => $request->validated('alt_text'),
                'sort_order' => is_null($maxSort) ? 0 : ((int) $maxSort + 1),
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $file->getMimeType(),
                'original_size_bytes' => $file->getSize(),
            ]);
        });

        $basePath = "images/{$pro->id}/{$media->id}";

        try {
            $originalPath = $this->mediaService->storeOriginal($file, $basePath);
        } catch (\Exception $e) {
            Log::error('Brand gallery: failed to store original', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $media->delete();

            return $this->error('Failed to store file.', 500);
        }

        $media->update(['path' => $originalPath]);

        $this->dispatchImageJob($media->id, $originalPath, $basePath);

        app(SiteCacheService::class)->invalidateSite($site);
        app(BrandStatusService::class)->sync($pro);

        $media->refresh();
        $media->load('mediaVariants');

        return $this->success($this->buildPayload($media), 201);
    }

    public function destroy(Request $request, string $mediaId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $media = SiteMedia::query()
            ->where('id', $mediaId)
            ->where('site_id', $site->id)
            ->where('pool', self::POOL)
            ->first();

        if (! $media) {
            return $this->error('Image not found.', 404);
        }

        $this->mediaService->deleteVariants($media->id, $media->path);
        $media->delete();

        app(SiteCacheService::class)->invalidateSite($site);
        app(BrandStatusService::class)->sync($pro);

        return $this->success(['ok' => true]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $ids = array_values(array_unique($request->validated('ids')));

        DB::transaction(function () use ($site, $ids) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }

            $images = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', self::POOL)
                ->where('is_active', true)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->get(['id', 'sort_order']);

            if ($images->isEmpty()) {
                abort(422, 'No active brand gallery images found.');
            }

            $ownedIds = $images->pluck('id')->flip();
            foreach ($ids as $id) {
                if (! isset($ownedIds[$id])) {
                    abort(422, 'One or more brand gallery items are invalid.');
                }
            }

            $remaining = array_values(array_diff($images->pluck('id')->all(), $ids));
            $ordered = array_merge($ids, $remaining);

            // Two-pass update to avoid unique constraint collisions on sort_order
            $offset = $images->count() + 1000;
            foreach ($ordered as $index => $id) {
                SiteMedia::where('site_id', $site->id)->where('id', $id)
                    ->update(['sort_order' => $offset + $index]);
            }
            foreach ($ordered as $index => $id) {
                SiteMedia::where('site_id', $site->id)->where('id', $id)
                    ->update(['sort_order' => $index]);
            }
        });

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['ok' => true]);
    }

    private function dispatchImageJob(string $imageId, string $originalPath, string $basePath): void
    {
        $processInline = in_array(app()->environment(), ['local', 'testing'], true)
            || config('queue.default', 'sync') === 'sync';

        try {
            if ($processInline) {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } else {
                ProcessImageVariantsJob::dispatch(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            }
        } catch (Throwable $e) {
            Log::error('Brand gallery: image processing dispatch failed', [
                'image_id' => $imageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildPayload(SiteMedia $media): array
    {
        $isReady = $media->processing_state === SiteMedia::PROCESSING_STATE_READY;

        return [
            'id' => $media->id,
            'alt_text' => $media->alt_text,
            'sort_order' => $media->sort_order,
            'processing_state' => $media->processing_state,
            'variants' => $isReady ? $media->variantUrls() : [],
            'created_at' => optional($media->created_at)->toIso8601String(),
        ];
    }
}
