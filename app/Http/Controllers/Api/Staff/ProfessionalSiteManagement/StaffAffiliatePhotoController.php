<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// V2 B6 #AFF-PHOTO-1: Staff admin for affiliate custom product photos. Read for any staff,
// delete (DMCA / inappropriate content takedowns) for admin staff only. Skip upload — that's
// user content.
class StaffAffiliatePhotoController extends ApiController
{
    private const POOL = SiteMedia::POOL_PRODUCT;

    private const GID_PATTERN = '/^gid:\/\/shopify\/Product\/\d+$/';

    public function __construct(
        private readonly ImageVariantService $mediaService,
        private readonly SiteCacheService $siteCache,
    ) {}

    /**
     * GET /api/staff/professionals/{professional}/affiliate/products/{gid}/photos
     */
    public function index(Request $request, Professional $professional, string $gid): JsonResponse
    {
        if (! preg_match(self::GID_PATTERN, $gid)) {
            return $this->error('Invalid product ID.', 422);
        }

        // The professional must actually own a selection for this product. Without this guard,
        // a staff member could enumerate photos across professionals by guessing GIDs.
        $hasSelection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $professional->id)
            ->where('shopify_product_gid', $gid)
            ->exists();

        if (! $hasSelection) {
            return $this->error('Product not found in this affiliate\'s selections.', 404);
        }

        $professional->loadMissing('site');
        $site = $professional->site;
        if (! $site) {
            return $this->error('Site not found for this professional.', 404);
        }

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
            'professional_id' => $professional->id,
            'limit' => $max,
        ]);
    }

    /**
     * DELETE /api/staff/professionals/{professional}/affiliate/products/{gid}/photos/{media}
     *
     * Admin-only — DMCA / inappropriate content takedown. Logged as `staff-aff-photo-delete`
     * (placeholder for #OPS-2 audit log).
     */
    public function destroy(Request $request, Professional $professional, string $gid, string $mediaId): JsonResponse
    {
        if (! preg_match(self::GID_PATTERN, $gid)) {
            return $this->error('Invalid product ID.', 422);
        }

        $hasSelection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $professional->id)
            ->where('shopify_product_gid', $gid)
            ->exists();

        if (! $hasSelection) {
            return $this->error('Product not found in this affiliate\'s selections.', 404);
        }

        $professional->loadMissing('site');
        $site = $professional->site;
        if (! $site) {
            return $this->error('Site not found for this professional.', 404);
        }

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

        $this->siteCache->invalidateSite($site);

        Log::info('staff-aff-photo-delete: affiliate custom product photo removed', [
            'action' => 'staff-aff-photo-delete',
            'professional_id' => $professional->id,
            'product_gid' => $gid,
            'media_id' => $mediaId,
        ]);

        return $this->success(['ok' => true]);
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
