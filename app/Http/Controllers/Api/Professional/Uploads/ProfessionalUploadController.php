<?php

namespace App\Http\Controllers\Api\Professional\Uploads;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Uploads\UploadImageRequest;
use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\SiteImage;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfessionalUploadController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly ImageVariantService $mediaService,
    ) {}

    /**
     * Upload an image to a pool (gallery or content).
     *
     * Stores the original on the media disk and dispatches a queue job
     * to generate WebP variants (thumb → hero). Returns immediately.
     *
     * POST /api/uploads  { pool: gallery|content, image: <file>, alt_text?: string }
     */
    public function upload(UploadImageRequest $request): JsonResponse
    {
        $pro  = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $pool = $request->validated('pool');
        $file = $request->file('image');

        Log::info('Image upload started', [
            'pro_id' => $pro->id,
            'site_id' => $site->id,
            'pool' => $pool,
            'file_size_kb' => $file->getSize() / 1024,
        ]);

        $maxImages = (int) config("comet.image_pools.{$pool}.max", 5);

        // --- Pool limit check (fast, non-locking) ---
        $activeCount = SiteImage::query()
            ->where('site_id', $site->id)
            ->where('pool', $pool)
            ->where('is_active', true)
            ->count();

        if ($activeCount >= $maxImages) {
            return $this->error(
                ucfirst($pool) . " image limit reached (max {$maxImages}).", 422
            );
        }

        // --- Create SiteImage row (with advisory lock for race safety) ---
        $image = DB::transaction(function () use ($site, $pool, $maxImages, $request) {
            // PostgreSQL advisory lock (optional optimization; lockForUpdate below provides locking on all DBs)
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["{$pool}:{$site->id}"]);
            }

            $activeCount = SiteImage::query()
                ->where('site_id', $site->id)
                ->where('pool', $pool)
                ->where('is_active', true)
                ->lockForUpdate()
                ->count();

            if ($activeCount >= $maxImages) {
                abort(422, ucfirst($pool) . " image limit reached (max {$maxImages}).");
            }

            $maxSort = SiteImage::query()
                ->where('site_id', $site->id)
                ->where('pool', $pool)
                ->where('is_active', true)
                ->max('sort_order');

            $image = SiteImage::create([
                'site_id'    => $site->id,
                'pool'       => $pool,
                'path'       => '', // populated after original is stored
                'alt_text'   => $request->validated('alt_text'),
                'sort_order' => is_null($maxSort) ? 0 : ((int) $maxSort + 1),
                'is_active'  => true,
            ]);
            
            Log::info('SiteImage row created', ['image_id' => $image->id]);
            
            return $image;
        });

        // --- Store original on media disk ---
        $basePath = "images/{$pro->id}/{$image->id}";
        
        try {
            Log::info('Storing original image to media disk', ['image_id' => $image->id, 'base_path' => $basePath]);
            $originalPath = $this->mediaService->storeOriginal($file, $basePath);
            Log::info('Original image stored successfully', ['image_id' => $image->id, 'path' => $originalPath]);
        } catch (\Exception $e) {
            Log::error('Failed to store original image', ['image_id' => $image->id, 'error' => $e->getMessage()]);
            // Clean up orphaned image row
            $image->delete();
            return $this->error('Failed to store image: ' . $e->getMessage(), 500);
        }

        $image->update(['path' => $originalPath]);

        // --- Dispatch variant generation ---
        // With QUEUE_CONNECTION=sync the job runs inline before the
        // response is sent; with a real queue it runs in the background.
        Log::info('Dispatching ProcessImageVariantsJob', ['image_id' => $image->id]);
        ProcessImageVariantsJob::dispatch(
            originalPath: $originalPath,
            imageId: $image->id,
            basePath: $basePath,
        );

        // After dispatch (sync = already done, async = still processing)
        $image->loadCount('variants');
        $processing = $image->variants_count === 0;

        $payload = [
            'id'            => $image->id,
            'pool'          => $pool,
            'original_path' => $originalPath,
            'processing'    => $processing,
        ];

        // When processing is already complete, include variant URLs
        if (! $processing) {
            $image->load('variants');
            $payload['variants'] = $image->variantUrls();
        }

        return $this->success($payload, 201);
    }

    /**
     * List all images for the authenticated professional, grouped by pool,
     * with their processed variant URLs.
     *
     * GET /api/images?pool=gallery|content  (pool is optional)
     */
    public function index(): JsonResponse
    {
        $pro  = $this->currentProfessional(request());
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $query = SiteImage::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->with('variants')
            ->orderBy('pool')
            ->orderBy('sort_order')
            ->orderBy('created_at');

        if (request()->has('pool')) {
            $pool = strtolower(trim(request()->input('pool')));
            if (in_array($pool, ['gallery', 'content'], true)) {
                $query->where('pool', $pool);
            }
        }

        $images = $query->get()->map(fn (SiteImage $img) => [
            'id'         => $img->id,
            'pool'       => $img->pool,
            'alt_text'   => $img->alt_text,
            'sort_order' => $img->sort_order,
            'variants'   => $img->variantUrls(),
            'processing' => $img->variants->isEmpty(),
            'created_at' => $img->created_at,
            'updated_at' => $img->updated_at,
        ]);

        return $this->success([
            'images' => $images,
            'limits' => [
                'gallery' => config('comet.image_pools.gallery.max', 5),
                'content' => config('comet.image_pools.content.max', 5),
            ],
        ]);
    }

    /**
     * Delete an image and all its variants from storage.
     *
     * DELETE /api/images/{image}
     */
    public function destroy(SiteImage $image): JsonResponse
    {
        $pro  = $this->currentProfessional(request());
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        abort_unless($image->site_id === $site->id, 404);

        // Delete variant files + original + DB rows
        $this->mediaService->deleteVariants($image->id, $image->path);

        $image->delete();

        return $this->success(['deleted' => true]);
    }
}
