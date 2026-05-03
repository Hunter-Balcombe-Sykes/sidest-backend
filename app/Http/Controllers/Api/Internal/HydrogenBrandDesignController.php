<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Media\BrandDesignMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

// Internal endpoint that gives Hydrogen the resolved brand-design shape for a
// brand. Reads tokens from site.settings.design and media from site_media
// (pool=design). Cached for 5 seconds at the controller boundary so dashboard
// saves surface on Hydrogen within its staleWhileRevalidate window; every
// write path explicitly busts via SiteCacheService::forgetBrandDesign.
class HydrogenBrandDesignController extends ApiController
{
    // 5s matches Hydrogen's Oxygen fetch staleWhileRevalidate window, so the
    // worst-case propagation without an explicit bust is ~5s. With write-path
    // invalidation firing, the stale window is effectively zero.
    private const CACHE_TTL_SECONDS = 5;

    public function __construct(
        private readonly BrandDesignMediaService $brandDesign,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $slug = strtolower(trim($slug));

        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $slug)) {
            return $this->error('Invalid brand slug.', 422);
        }

        $professional = Professional::query()
            ->whereRaw('lower(handle) = ?', [$slug])
            ->first();

        if (! $professional || $professional->status !== 'active') {
            return $this->error('Brand not found or inactive.', 404);
        }

        // Site lookup happens outside the cache closure so the cache key is
        // site-scoped (the invalidation helper only needs the site id). Brands
        // without a site still go through the cache so repeated 404-shaped
        // responses don't hammer the DB.
        $site = Site::where('professional_id', $professional->id)->first();
        $cacheKey = CacheKeyGenerator::hydrogenBrandDesign((string) ($site?->id ?? "nosite:{$professional->id}"));

        $payload = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildDesignPayload($professional, $site)
        );

        return $this->success($payload);
    }

    /**
     * Build the design payload Hydrogen consumes.
     *
     * @return array{
     *     brand_professional_id: string,
     *     brand_handle: string|null,
     *     brand_name: string|null,
     *     shop_domain: string|null,
     *     colors: array{accent: ?string},
     *     theme_mode: string,
     *     corner_radius: ?string,
     *     border_thickness: ?string,
     *     section_spacing: ?string,
     *     logo: array{full_url: ?string, square_url: ?string},
     *     slogan: ?string,
     *     font_family: ?string,
     *     placeholders: array<int, array{url: string, alt_text: ?string}>,
     *     fallback_gallery: array<int, array{url: ?string, alt_text: ?string}>
     * }
     */
    private function buildDesignPayload(Professional $professional, ?Site $site): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];

        $colors = is_array($design['colors'] ?? null) ? $design['colors'] : [];

        // shop_domain still lives on provider_metadata — the one field we read
        // from there, purely so the Hydrogen layer can key on it when needed.
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();
        $metadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];

        // Logo + placeholders live in site_media (pool=design, purpose=...).
        // The service resolves variant URLs into the canonical service shape;
        // the Hydrogen response keeps only the fields Hydrogen renders.
        $designMedia = $site
            ? $this->brandDesign->listDesignMedia((string) $site->id)
            : ['logo' => ['full_url' => null, 'square_url' => null], 'placeholders' => []];

        return [
            'brand_professional_id' => (string) $professional->id,
            'brand_handle' => $professional->handle,
            'brand_name' => $professional->display_name,
            'shop_domain' => Arr::get($metadata, 'shop_domain'),
            'colors' => [
                'accent' => $colors['accent'] ?? null,
            ],
            // theme_mode replaces the brand-picked background/text/border triple.
            // Default to 'light' so Hydrogen never renders an unset surface.
            'theme_mode' => is_string($design['theme_mode'] ?? null) && $design['theme_mode'] !== ''
                ? $design['theme_mode']
                : 'light',
            'corner_radius' => $design['corner_radius'] ?? null,
            'border_thickness' => $design['border_thickness'] ?? null,
            'section_spacing' => $design['section_spacing'] ?? null,
            'logo' => $designMedia['logo'],
            'slogan' => $design['slogan'] ?? null,
            'font_family' => is_string($design['font_family'] ?? null) && $design['font_family'] !== ''
                ? $design['font_family']
                : null,
            // Hydrogen-facing shape: just the fields Hydrogen renders. The
            // service-returned id and sort_order are intentionally omitted —
            // Hydrogen doesn't manage these rows, only displays them.
            'placeholders' => array_map(
                fn (array $item) => [
                    'url' => $item['url'],
                    'alt_text' => $item['alt_text'],
                ],
                $designMedia['placeholders']
            ),
            'fallback_gallery' => $this->getFallbackGallery($site),
        ];
    }

    /**
     * @return array<int, array{url: ?string, alt_text: ?string}>
     */
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
