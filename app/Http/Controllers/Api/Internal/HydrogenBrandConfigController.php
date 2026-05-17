<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// V2: Internal endpoint for Hydrogen loaders. Returns brand config, storefront token, and shop metafield values by shop domain.
class HydrogenBrandConfigController extends ApiController
{
    // 60s primary TTL with a 10× stale window via CacheLockService. Hot read
    // path (every Hydrogen storefront initial render). Busted on writes to
    // ProfessionalIntegration, BrandStoreSettings, the brand's Site, and
    // SiteMedia rows where pool=brand_gallery.
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly CacheLockService $cacheLock,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'shop_domain' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $shopDomain = strtolower(trim($validator->validated()['shop_domain']));

        $cacheKey = CacheKeyGenerator::hydrogenBrandConfig($shopDomain);

        // Cache the full payload including 404 lookups would be wrong (a brand
        // that installs Shopify post-cache would be locked out). So gate runs
        // outside the cache; only the success-path payload is cached. The
        // gate's 1 DB query is cheap relative to the 5+ queries this saves.
        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('Brand not found for this shop domain.', 404);
        }

        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildBrandConfigPayload($integration, $shopDomain),
        );

        // null surfaces as a 404. writeWithJitter does Cache::put the null,
        // but Cache::get() returns null for both "stored null" and "key
        // absent", so the next request recomputes — a brand becoming active
        // is visible on the next call. Minor Redis write waste on this rare
        // path is the deliberate tradeoff vs rememberLockedNullable.
        if ($payload === null) {
            return $this->error('Brand not found or inactive.', 404);
        }

        return $this->success($payload);
    }

    /**
     * Build the brand-config payload. Returns null when the professional is
     * absent or inactive so show() can map to a 404 — and the null itself is
     * not cached (CacheLockService::rememberLocked treats null as "miss" and
     * recomputes next call). This is intentional: the negative case here
     * should retry quickly when a brand becomes active.
     *
     * @return array<string, mixed>|null
     */
    private function buildBrandConfigPayload(ProfessionalIntegration $integration, string $shopDomain): ?array
    {
        $professional = Professional::find($integration->professional_id);

        if (! $professional || $professional->status !== 'active') {
            return null;
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $site = Site::where('professional_id', $professional->id)->first();
        $storeSettings = BrandStoreSettings::where('professional_id', $professional->id)->first();

        $siteSettings = is_array($site?->settings) ? $site->settings : [];

        $design = is_array(Arr::get($siteSettings, 'design')) ? Arr::get($siteSettings, 'design') : [];
        $typography = is_array($design['typography'] ?? null) ? $design['typography'] : [];
        $media = is_array($design['media'] ?? null) ? $design['media'] : [];

        return [
            'brand_professional_id' => (string) $professional->id,
            'brand_name' => $professional->display_name,
            'brand_handle' => $professional->handle,
            'shop_domain' => $shopDomain,
            'storefront_access_token' => $integration->storefront_token,
            'default_commission_rate' => $storeSettings ? (float) $storeSettings->default_commission_rate : 15.0,
            'currency_code' => Arr::get($metadata, 'shop_currency', 'AUD'),
            'active_collection_handle' => Arr::get($metadata, 'active_collection_handle', 'partna-active-products'),
            'default_collection_handle' => Arr::get($metadata, 'default_collection_handle', 'partna-default-products'),
            'favourites_collection_handle' => Arr::get($metadata, 'favourites_collection_handle', 'partna-brand-favourites'),
            'high_commission_collection_handle' => Arr::get($metadata, 'high_commission_collection_handle', 'partna-high-commission'),
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
        ];
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
