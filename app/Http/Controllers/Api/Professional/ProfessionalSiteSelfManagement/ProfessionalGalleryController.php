<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\ImageGallery\ReorderGalleryImageRequest;
use App\Models\Core\Site\SiteImage;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProfessionalGalleryController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly ImageVariantService $mediaService,
    ) {}

    /**
     * List gallery-pool images for the current site, eager-loading variants.
     */
    public function index(): JsonResponse
    {
        $pro  = $this->currentProfessional(request());
        $site = $this->currentSite($pro);

        $images = SiteImage::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteImage::POOL_GALLERY)
            ->where('is_active', true)
            ->with('variants')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        $result = $images->map(fn (SiteImage $img) => [
            'id'         => $img->id,
            'pool'       => $img->pool,
            'alt_text'   => $img->alt_text,
            'sort_order' => $img->sort_order,
            'variants'   => $img->variantUrls(),
            'created_at' => $img->created_at,
            'updated_at' => $img->updated_at,
        ]);

        return $this->success(['images' => $result]);
    }

    /**
     * Gallery uploads are now handled by POST /api/uploads with pool=gallery.
     *
     * @deprecated Use POST /api/uploads with pool=gallery instead.
     */
    public function store(): JsonResponse
    {
        return $this->error(
            'Gallery image creation has moved to POST /api/uploads with pool=gallery. '
            . 'Upload the image file directly instead of passing bucket/path.',
            410,
        );
    }

    public function reorder(ReorderGalleryImageRequest $request): JsonResponse
    {
        $pro  = $this->currentProfessional(request());
        $site = $this->currentSite($pro);

        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($site, $ids) {
            $allIds = SiteImage::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteImage::POOL_GALLERY)
                ->where('is_active', true)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);
            foreach ($ids as $id) {
                if (!isset($allSet[$id])) {
                    abort(403, 'One or more images do not belong to your site.');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder  = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                SiteImage::query()
                    ->where('site_id', $site->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['ok' => true]);
    }

    /**
     * Soft-delete the gallery image and clean up its variants from storage.
     */
    public function destroy(SiteImage $image): JsonResponse
    {
        $pro  = $this->currentProfessional(request());
        $site = $this->currentSite($pro);
        abort_unless($image->site_id === $site->id, 404);

        $this->mediaService->deleteVariants($image->id, $image->path);
        $image->delete();

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['deleted' => true]);
    }
}
