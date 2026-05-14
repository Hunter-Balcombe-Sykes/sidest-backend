<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Store\CustomPhotoPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Internal endpoint for Hydrogen loaders. Returns ordered product GIDs for an
 * affiliate (or the brand's default collection as a fallback), plus the metadata
 * Hydrogen needs to render:
 *
 *   - custom_photos: per-product affiliate-uploaded lifestyle photos
 *   - custom_photo_position: where to place them in the product gallery
 *
 * Variant restrictions (sidest.enabled) are read by Hydrogen directly from the
 * Storefront API via variant-level metafields with PUBLIC_READ access — no
 * intermediary needed. This endpoint tells Hydrogen WHICH products and photos
 * to show; Shopify tells it which variants are enabled.
 */
class HydrogenAffiliateProductsController extends ApiController
{
    // 60s primary TTL. Push invalidation via
    // SiteCacheService::forgetHydrogenAffiliateProducts on AffiliateProductSelection
    // writes and on SiteMedia (pool=POOL_PRODUCT) writes keeps the cache fresh
    // when affiliates curate; the TTL covers brand-level toggles
    // (custom_photos_enabled).
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly CacheLockService $cacheLock,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'affiliate_id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $affiliateId = $validator->validated()['affiliate_id'];

        $cacheKey = CacheKeyGenerator::hydrogenAffiliateProducts($affiliateId);

        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildProductsPayload($affiliateId),
        );

        // null = no BrandPartnerLink found. Surfaced as 404 so the response
        // matches the pre-cache contract. The null IS written to the cache
        // by writeWithJitter, but Cache::get() can't distinguish "stored null"
        // from "key absent" — so the very next request reads null, treats it
        // as a miss, and recomputes. Net effect: a brand-link transition is
        // visible on the next read, at the cost of a tiny Redis write+read
        // pair on the rare 404 path. This is the deliberate tradeoff vs
        // switching to rememberLockedNullable with a sentinel.
        if ($payload === null) {
            return $this->error('Affiliate not linked to any brand.', 404);
        }

        return $this->success($payload);
    }

    /**
     * Build the products payload. Returns null when the affiliate has no
     * BrandPartnerLink so show() can map to a 404.
     *
     * @return array<string, mixed>|null
     */
    private function buildProductsPayload(string $affiliateId): ?array
    {
        // Selected products ordered by sort_order.
        $selections = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliateId)
            ->orderBy('sort_order')
            ->pluck('shopify_product_gid')
            ->all();

        // Resolve brand link for both selection paths.
        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateId)
            ->first();

        if (! $link) {
            return null;
        }

        $brandId = (string) $link->brand_professional_id;

        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $brandId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        $metadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];

        if (! empty($selections)) {
            $permissions = app(CustomPhotoPermissionService::class);
            $customPhotos = $permissions->isAllowed($brandId)
                ? $this->getCustomPhotos($affiliateId, $selections)
                : [];

            return [
                'gids' => $selections,
                'source' => 'affiliate_selections',
                'custom_photo_position' => $permissions->getPhotoPosition($brandId),
                'custom_photos' => $customPhotos,
            ];
        }

        return [
            'gids' => [],
            'source' => 'default_collection',
            'default_collection_handle' => Arr::get($metadata, 'default_collection_handle', 'partna-default-products'),
        ];
    }

    private function getCustomPhotos(string $affiliateId, array $productGids): array
    {
        $site = Site::query()
            ->where('professional_id', $affiliateId)
            ->first();

        if (! $site) {
            return [];
        }

        $media = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_PRODUCT)
            ->whereIn('product_gid', $productGids)
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->with('mediaVariants')
            ->orderBy('product_gid')
            ->orderBy('sort_order')
            ->get();

        $grouped = [];
        foreach ($media as $item) {
            $url = $item->variantUrls()['optimized'] ?? null;
            if ($url === null) {
                continue;
            }

            $gid = $item->product_gid;
            if (! isset($grouped[$gid])) {
                $grouped[$gid] = [];
            }

            $grouped[$gid][] = [
                'url' => $url,
                'alt_text' => $item->alt_text,
            ];
        }

        return $grouped;
    }
}
