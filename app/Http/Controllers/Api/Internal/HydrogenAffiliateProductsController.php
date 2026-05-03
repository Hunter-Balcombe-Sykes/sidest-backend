<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
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
    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'affiliate_id' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $affiliateId = $validator->validated()['affiliate_id'];

        // Get affiliate's selected products ordered by sort_order
        $selections = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliateId)
            ->orderBy('sort_order')
            ->pluck('shopify_product_gid')
            ->all();

        // Resolve brand link for both selection paths
        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateId)
            ->first();

        if (! $link) {
            return $this->error('Affiliate not linked to any brand.', 404);
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

            return $this->success([
                'gids' => $selections,
                'source' => 'affiliate_selections',
                'custom_photo_position' => $permissions->getPhotoPosition($brandId),
                'custom_photos' => $customPhotos,
            ]);
        }

        return $this->success([
            'gids' => [],
            'source' => 'default_collection',
            'default_collection_handle' => Arr::get($metadata, 'default_collection_handle', 'sidest-default-products'),
        ]);
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
