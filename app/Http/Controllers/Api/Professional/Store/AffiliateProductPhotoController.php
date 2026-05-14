<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Jobs\ProcessImageVariantsJob;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use App\Services\Store\AffiliateProductCatalogService;
use App\Services\Store\CustomPhotoPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AffiliateProductPhotoController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    private const POOL = SiteMedia::POOL_PRODUCT;

    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const MAX_FILE_KB = 10240;

    private const GID_PATTERN = '/^gid:\/\/shopify\/Product\/\d+$/';

    public function __construct(
        private readonly ImageVariantService $mediaService,
        private readonly CustomPhotoPermissionService $permissions,
        private readonly AffiliateProductCatalogService $catalogService,
    ) {}

    public function index(Request $request, string $gid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! preg_match(self::GID_PATTERN, $gid)) {
            return $this->error('Invalid product ID.', 422);
        }

        // Verify the GID is in the affiliate's selections to prevent IDOR reads
        $hasSelection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->exists();

        if (! $hasSelection) {
            return $this->error('Product not found in your selections.', 404);
        }

        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $images = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', self::POOL)
            ->where('product_gid', $gid)
            ->where('is_active', true)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteMedia $media) => $this->buildPayload($media));

        $max = (int) config('partna.image_pools.product_custom.max', 3);

        return $this->success([
            'images' => $images,
            'product_gid' => $gid,
            'limit' => $max,
        ]);
    }

    public function upload(Request $request, string $gid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! preg_match(self::GID_PATTERN, $gid)) {
            return $this->error('Invalid product ID.', 422);
        }

        $request->validate([
            'image' => [
                'required',
                'file',
                'mimetypes:'.implode(',', self::ALLOWED_MIMES),
                'max:'.self::MAX_FILE_KB,
            ],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);

        // Verify product is in affiliate's selections
        $hasSelection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->exists();

        if (! $hasSelection) {
            return $this->error('You can only upload photos for products you have selected.', 422);
        }

        // Check permission (per-affiliate → global)
        try {
            $resolved = $this->catalogService->resolveAffiliateBrandIntegration($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        }

        if (! $this->permissions->isAllowed($resolved['brand_professional_id'], $gid)) {
            return $this->error('Custom product photos are not enabled for this product.', 403);
        }

        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $file = $request->file('image');
        $maxItems = (int) config('partna.image_pools.product_custom.max', 3);

        $media = DB::transaction(function () use ($site, $gid, $maxItems, $request, $file) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-product-photos:{$site->id}:{$gid}"]);
            }

            $activeCount = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', self::POOL)
                ->where('product_gid', $gid)
                ->where('is_active', true)
                ->lockForUpdate()
                ->count();

            if ($activeCount >= $maxItems) {
                abort(422, "Custom photo limit reached for this product (max {$maxItems}).");
            }

            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => self::POOL,
                'product_gid' => $gid,
                'path' => '',
                'alt_text' => $request->validated('alt_text'),
                'sort_order' => $activeCount,
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
            Log::error('Product photo: failed to store original', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $media->delete();

            return $this->error('Failed to store file.', 500);
        }

        $media->update(['path' => $originalPath]);

        $this->dispatchImageJob($media->id, $originalPath, $basePath);

        app(SiteCacheService::class)->invalidateSite($site);

        $media->refresh();
        $media->load('mediaVariants');

        return $this->success($this->buildPayload($media), 201);
    }

    public function destroy(Request $request, string $gid, string $mediaId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! preg_match(self::GID_PATTERN, $gid)) {
            return $this->error('Invalid product ID.', 422);
        }

        // Verify the GID is in the affiliate's selections to prevent IDOR deletes
        $hasSelection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->exists();

        if (! $hasSelection) {
            return $this->error('Product not found in your selections.', 404);
        }

        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $media = SiteMedia::query()
            ->where('id', $mediaId)
            ->where('site_id', $site->id)
            ->where('pool', self::POOL)
            ->where('product_gid', $gid)
            ->first();

        if (! $media) {
            return $this->error('Photo not found.', 404);
        }

        $this->mediaService->deleteVariants($media->id, $media->path);
        $media->delete();

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['ok' => true]);
    }

    public function reorder(Request $request, string $gid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! preg_match(self::GID_PATTERN, $gid)) {
            return $this->error('Invalid product ID.', 422);
        }

        // Verify the GID is in the affiliate's selections to prevent IDOR reorders
        $hasSelection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->exists();

        if (! $hasSelection) {
            return $this->error('Product not found in your selections.', 404);
        }

        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $pro->loadMissing('site');
        $site = $this->currentSite($pro);
        $ids = array_values(array_unique($request->validated('ids')));

        DB::transaction(function () use ($site, $gid, $ids) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-product-photos:{$site->id}:{$gid}"]);
            }

            $images = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', self::POOL)
                ->where('product_gid', $gid)
                ->where('is_active', true)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->get(['id', 'sort_order']);

            if ($images->isEmpty()) {
                abort(422, 'No custom photos found for this product.');
            }

            $ownedIds = $images->pluck('id')->flip();
            foreach ($ids as $id) {
                if (! isset($ownedIds[$id])) {
                    abort(422, 'One or more product photos are invalid.');
                }
            }

            $remaining = array_values(array_diff($images->pluck('id')->all(), $ids));
            $ordered = array_merge($ids, $remaining);

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
            report($e);
            Log::error('Product photo: image processing dispatch failed', [
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
            'product_gid' => $media->product_gid,
            'alt_text' => $media->alt_text,
            'sort_order' => $media->sort_order,
            'processing_state' => $media->processing_state,
            'variants' => $isReady ? $media->variantUrls() : [],
            'created_at' => optional($media->created_at)->toIso8601String(),
        ];
    }
}
