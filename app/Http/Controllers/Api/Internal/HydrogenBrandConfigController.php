<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// V2: Internal endpoint for Hydrogen loaders. Returns brand config, storefront token, and shop metafield values by shop domain.
class HydrogenBrandConfigController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'shop_domain' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $shopDomain = strtolower(trim($validator->validated()['shop_domain']));

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('Brand not found for this shop domain.', 404);
        }

        $professional = Professional::find($integration->professional_id);

        if (! $professional || $professional->status !== 'active') {
            return $this->error('Brand not found or inactive.', 404);
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $site = Site::where('professional_id', $professional->id)->first();
        $storeSettings = BrandStoreSettings::where('professional_id', $professional->id)->first();

        $siteSettings = is_array($site?->settings) ? $site->settings : [];

        return $this->success([
            'brand_professional_id' => (string) $professional->id,
            'brand_name' => $professional->display_name,
            'brand_handle' => $professional->handle,
            'shop_domain' => $shopDomain,
            'storefront_access_token' => Arr::get($metadata, 'storefront_access_token'),
            'default_commission_rate' => $storeSettings ? (float) $storeSettings->default_commission_rate : 15.0,
            'currency_code' => Arr::get($metadata, 'shop_currency', 'AUD'),
            'theme_variant' => Arr::get($metadata, 'theme_variant'),
            'accent_color' => Arr::get($metadata, 'accent_color'),
            'product_image_ratio' => Arr::get($metadata, 'product_image_ratio'),
            'brand_logo_url' => Arr::get($siteSettings, 'design.media.brand_logo_url'),
            'active_collection_handle' => Arr::get($metadata, 'active_collection_handle', 'sidest-active-products'),
            'default_collection_handle' => Arr::get($metadata, 'default_collection_handle', 'sidest-default-products'),
            'favourites_collection_handle' => Arr::get($metadata, 'favourites_collection_handle', 'sidest-brand-favourites'),
            'high_commission_collection_handle' => Arr::get($metadata, 'high_commission_collection_handle', 'sidest-high-commission'),
            'fallback_gallery' => $this->getFallbackGallery($site),
        ]);
    }

    private function getFallbackGallery(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        return SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_BRAND_GALLERY)
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteMedia $media) => [
                'url' => $media->variantUrls()['optimized'] ?? null,
                'alt_text' => $media->alt_text,
            ])
            ->filter(fn (array $item) => $item['url'] !== null)
            ->values()
            ->all();
    }
}
