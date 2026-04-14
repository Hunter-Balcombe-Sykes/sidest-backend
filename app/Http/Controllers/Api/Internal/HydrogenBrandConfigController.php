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

        $design = is_array(Arr::get($siteSettings, 'design')) ? Arr::get($siteSettings, 'design') : [];
        $typography = is_array($design['typography'] ?? null) ? $design['typography'] : [];
        $media = is_array($design['media'] ?? null) ? $design['media'] : [];

        return $this->success([
            'brand_professional_id' => (string) $professional->id,
            'brand_name' => $professional->display_name,
            'brand_handle' => $professional->handle,
            'shop_domain' => $shopDomain,
            'storefront_access_token' => Arr::get($metadata, 'storefront_access_token'),
            'default_commission_rate' => $storeSettings ? (float) $storeSettings->default_commission_rate : 15.0,
            'currency_code' => Arr::get($metadata, 'shop_currency', 'AUD'),
            'active_collection_handle' => Arr::get($metadata, 'active_collection_handle', 'sidest-active-products'),
            'default_collection_handle' => Arr::get($metadata, 'default_collection_handle', 'sidest-default-products'),
            'favourites_collection_handle' => Arr::get($metadata, 'favourites_collection_handle', 'sidest-brand-favourites'),
            'high_commission_collection_handle' => Arr::get($metadata, 'high_commission_collection_handle', 'sidest-high-commission'),
            'custom_photos_enabled' => (bool) Arr::get($metadata, 'custom_photos_enabled', true),
            'theme_id' => $storeSettings ? (int) $storeSettings->theme_id : 1,
            'design' => [
                'accent_color' => $design['accent_color'] ?? null,
                'dark_color' => $design['dark_color'] ?? null,
                'white_color' => $design['white_color'] ?? null,
                'border_color' => $design['border_color'] ?? null,
                'primary_color' => $design['primary_color'] ?? null,
                'secondary_color' => $design['secondary_color'] ?? null,
                'background_color' => $design['background_color'] ?? null,
                'text_color' => $design['text_color'] ?? null,
                'button_background' => $design['button_background'] ?? null,
                'button_text_color' => $design['button_text_color'] ?? null,
                'border_radius' => $design['border_radius'] ?? null,
                'border_width' => $design['border_width'] ?? null,
                'theme_variant' => $design['theme_variant'] ?? null,
                'product_image_ratio' => $design['product_image_ratio'] ?? null,
                'custom_photo_position' => $design['custom_photo_position'] ?? 'after',
                'heading_font' => $typography['heading_font'] ?? null,
                'body_font' => $typography['body_font'] ?? null,
                'brand_logo_url' => is_string($media['brand_logo_url'] ?? null) ? $media['brand_logo_url'] : null,
            ],
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
