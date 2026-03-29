<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Store\UploadProductMediaRequest;
use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\SiteMedia;
use App\Models\Retail\BrandAffiliateSettings;
use App\Models\Retail\BrandProduct;
use App\Models\Retail\BrandProductMedia;
use App\Models\Retail\BrandProductSetting;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BrandProductMediaController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly ImageVariantService $mediaService,
    ) {}

    /**
     * GET /store/products/{brandProductId}/media
     * List all custom media uploaded by the authenticated affiliate for a product.
     */
    public function index(Request $request, string $brandProductId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $product = BrandProduct::query()->find($brandProductId);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }

        $items = BrandProductMedia::query()
            ->where('brand_product_id', $brandProductId)
            ->where('professional_id', $pro->id)
            ->with(['siteMedia.mediaVariants'])
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        $media = $items->map(fn (BrandProductMedia $pm) => $this->buildPayload($pm));

        return $this->success([
            'media'  => $media,
            'limit'  => (int) config('comet.image_pools.product.max', 5),
            'count'  => $items->count(),
        ]);
    }

    /**
     * POST /store/products/{brandProductId}/media
     * Upload a custom product photo as an affiliate.
     */
    public function upload(UploadProductMediaRequest $request, string $brandProductId): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $product = BrandProduct::query()->find($brandProductId);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }

        // Brands cannot upload affiliate-style photos for their own products.
        if ((string) $pro->id === (string) $product->brand_professional_id) {
            return $this->error('Brand accounts cannot upload affiliate product media.', 403);
        }

        // --- 3-tier brand toggle enforcement (all default to allowed when no row exists) ---

        $brandSettings = BrandStoreSettings::query()
            ->where('professional_id', $product->brand_professional_id)
            ->first();
        if ($brandSettings && ! (bool) $brandSettings->allow_affiliate_media) {
            return $this->error('This brand has disabled affiliate product media uploads.', 403);
        }

        $productSetting = BrandProductSetting::query()
            ->where('professional_id', $product->brand_professional_id)
            ->where('brand_product_id', $brandProductId)
            ->first();
        if ($productSetting && ! (bool) $productSetting->allow_affiliate_media) {
            return $this->error('Affiliate media uploads are disabled for this product.', 403);
        }

        $affiliateSetting = BrandAffiliateSettings::query()
            ->where('brand_professional_id', $product->brand_professional_id)
            ->where('affiliate_professional_id', $pro->id)
            ->first();
        if ($affiliateSetting && ! (bool) $affiliateSetting->allow_affiliate_media) {
            return $this->error('The brand has disabled your ability to upload product media.', 403);
        }

        $maxItems = (int) config('comet.image_pools.product.max', 5);

        // Pre-check (outside transaction) to give a fast 422 before locking.
        $existingCount = BrandProductMedia::query()
            ->where('brand_product_id', $brandProductId)
            ->where('professional_id', $pro->id)
            ->count();

        if ($existingCount >= $maxItems) {
            return $this->error("Product media limit reached (max {$maxItems}).", 422);
        }

        $file = $request->file('image');

        // Create SiteMedia row inside a transaction with advisory lock to prevent races.
        $media = DB::transaction(function () use ($site, $brandProductId, $pro, $maxItems, $request, $file): SiteMedia {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["product-media:{$pro->id}:{$brandProductId}"]);
            }

            $count = BrandProductMedia::query()
                ->where('brand_product_id', $brandProductId)
                ->where('professional_id', $pro->id)
                ->lockForUpdate()
                ->count();

            if ($count >= $maxItems) {
                abort(422, "Product media limit reached (max {$maxItems}).");
            }

            $maxSort = BrandProductMedia::query()
                ->where('brand_product_id', $brandProductId)
                ->where('professional_id', $pro->id)
                ->max('sort_order');

            return SiteMedia::create([
                'site_id'             => $site->id,
                'pool'                => SiteMedia::POOL_PRODUCT,
                'path'                => '',
                'alt_text'            => $request->validated('alt_text'),
                'sort_order'          => is_null($maxSort) ? 0 : ((int) $maxSort + 1),
                'is_active'           => true,
                'media_type'          => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state'    => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime'       => $file->getMimeType(),
                'original_size_bytes' => $file->getSize(),
            ]);
        });

        // Store original file on media disk.
        $basePath = "images/{$pro->id}/products/{$brandProductId}/{$media->id}";

        try {
            $originalPath = $this->mediaService->storeOriginal($file, $basePath);
        } catch (\Exception $e) {
            Log::error('Failed to store product media original.', [
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
            ]);
            $media->delete();

            return $this->error('Failed to store file: ' . $e->getMessage(), 500);
        }

        $media->update(['path' => $originalPath]);

        // Link media to product.
        BrandProductMedia::create([
            'brand_product_id' => $brandProductId,
            'professional_id'  => (string) $pro->id,
            'site_media_id'    => $media->id,
            'sort_order'       => (int) $media->sort_order,
        ]);

        // Dispatch image processing.
        $this->dispatchImageJob($media->id, $originalPath, $basePath);

        app(SiteCacheService::class)->invalidateSite($site);

        $media->refresh();
        $media->load('mediaVariants');

        return $this->success([
            'id'               => $media->id,
            'alt_text'         => $media->alt_text,
            'sort_order'       => $media->sort_order,
            'processing_state' => $media->processing_state,
            'processing'       => $media->processing_state !== SiteMedia::PROCESSING_STATE_READY,
            'variants'         => $media->processing_state === SiteMedia::PROCESSING_STATE_READY
                ? $media->variantUrls()
                : [],
            'created_at'       => $media->created_at,
            'updated_at'       => $media->updated_at,
        ], 201);
    }

    /**
     * POST /store/products/{brandProductId}/media/reorder
     * Reorder custom product media for the authenticated affiliate.
     * Body: { ids: [uuid, ...] } — full ordered list of BrandProductMedia IDs.
     */
    public function reorder(Request $request, string $brandProductId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $product = BrandProduct::query()->find($brandProductId);
        if (! $product) {
            return $this->error('Product not found.', 404);
        }

        $ids = array_values(array_unique((array) $request->input('ids', [])));

        DB::transaction(function () use ($pro, $brandProductId, $ids): void {
            $items = BrandProductMedia::query()
                ->where('brand_product_id', $brandProductId)
                ->where('professional_id', $pro->id)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->get(['id', 'sort_order']);

            if ($items->isEmpty()) {
                return;
            }

            $ownedIds = $items->pluck('id')->map(fn ($id) => (string) $id)->flip()->all();

            foreach ($ids as $id) {
                if (! isset($ownedIds[(string) $id])) {
                    abort(403, 'One or more items do not belong to your product media.');
                }
            }

            // Merge provided order + append any un-mentioned IDs at the end.
            $remaining   = array_values(array_diff(array_keys($ownedIds), $ids));
            $orderedIds  = array_merge($ids, $remaining);

            // Two-pass update to avoid unique constraint conflicts mid-update.
            $offset = $items->count() + 1000;
            foreach ($orderedIds as $i => $id) {
                BrandProductMedia::query()
                    ->where('id', $id)
                    ->where('professional_id', $pro->id)
                    ->update(['sort_order' => $offset + $i]);
            }
            foreach ($orderedIds as $i => $id) {
                BrandProductMedia::query()
                    ->where('id', $id)
                    ->where('professional_id', $pro->id)
                    ->update(['sort_order' => $i]);
            }
        });

        return $this->success(['ok' => true]);
    }

    /**
     * DELETE /store/products/{brandProductId}/media/{mediaId}
     * Delete a custom product photo uploaded by the authenticated affiliate.
     * {mediaId} is the BrandProductMedia.id (not the SiteMedia.id).
     */
    public function destroy(Request $request, string $brandProductId, string $mediaId): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $productMedia = BrandProductMedia::query()
            ->where('id', $mediaId)
            ->where('brand_product_id', $brandProductId)
            ->where('professional_id', $pro->id)
            ->with('siteMedia')
            ->first();

        if (! $productMedia) {
            return $this->error('Media not found.', 404);
        }

        $siteMedia = $productMedia->siteMedia;

        if ($siteMedia) {
            $this->mediaService->deleteVariants($siteMedia->id, $siteMedia->path);
            $siteMedia->delete();
        }

        $productMedia->delete();

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['deleted' => true]);
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function buildPayload(BrandProductMedia $pm): array
    {
        $siteMedia = $pm->siteMedia;

        if (! $siteMedia) {
            return [
                'id'               => $pm->id,
                'site_media_id'    => $pm->site_media_id,
                'sort_order'       => $pm->sort_order,
                'processing_state' => SiteMedia::PROCESSING_STATE_FAILED,
                'processing'       => false,
                'variants'         => [],
                'alt_text'         => null,
                'created_at'       => $pm->created_at,
                'updated_at'       => $pm->updated_at,
            ];
        }

        $isReady = $siteMedia->processing_state === SiteMedia::PROCESSING_STATE_READY;

        return [
            'id'               => $pm->id,
            'site_media_id'    => $pm->site_media_id,
            'sort_order'       => $pm->sort_order,
            'alt_text'         => $siteMedia->alt_text,
            'processing_state' => $siteMedia->processing_state,
            'processing'       => ! $isReady,
            'processing_error' => $siteMedia->processing_error,
            'variants'         => $isReady ? $siteMedia->variantUrls() : [],
            'created_at'       => $pm->created_at,
            'updated_at'       => $pm->updated_at,
        ];
    }

    private function dispatchImageJob(string $imageId, string $originalPath, string $basePath): void
    {
        $queueConnection = (string) config('queue.default', 'sync');
        $processInline   = in_array(app()->environment(), ['local', 'testing'], true)
            || $queueConnection === 'sync';

        if ($processInline) {
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $e) {
                Log::error('Inline product media variant processing failed.', [
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
            Log::error('Queue dispatch failed for product media; trying synchronous fallback.', [
                'image_id' => $imageId, 'error' => $e->getMessage(),
            ]);
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $syncError) {
                Log::error('Synchronous product media variant processing also failed.', [
                    'image_id' => $imageId, 'error' => $syncError->getMessage(),
                ]);
            }
        }
    }
}
