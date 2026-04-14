<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

// Internal endpoint that gives Hydrogen the resolved brand-design shape for a
// brand. Reads from the unified source of truth (site.settings.design) — the
// old provider_metadata.theme_tokens / sitepage_overrides storage was retired.
// Cached for 5 minutes; busted by SyncShopifyBrandDesignJob and by site updates.
class HydrogenBrandDesignController extends ApiController
{
    private const CACHE_TTL_SECONDS = 300;

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

        $payload = Cache::remember(
            CacheKeyGenerator::brandDesignConfig((string) $professional->id),
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildDesignPayload($professional)
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
     *     colors: array{background: ?string, text: ?string, accent: ?string, border: ?string},
     *     corner_radius: ?string,
     *     border_thickness: ?string,
     *     section_spacing: ?string,
     *     logo: array{full_url: ?string, square_url: ?string},
     *     slogan: ?string,
     *     font_family: ?string,
     *     placeholder_sitepage_images: array<int, array{url: string, name: ?string, path: ?string}>,
     *     fallback_gallery: array<int, array{url: ?string, alt_text: ?string}>
     * }
     */
    private function buildDesignPayload(Professional $professional): array
    {
        $site = Site::where('professional_id', $professional->id)->first();
        $settings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];

        $colors = is_array($design['colors'] ?? null) ? $design['colors'] : [];
        $logo = is_array($design['logo'] ?? null) ? $design['logo'] : [];
        $media = is_array($design['media'] ?? null) ? $design['media'] : [];

        // shop_domain still lives on provider_metadata — the one field we read
        // from there, purely so the Hydrogen layer can key on it when needed.
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();
        $metadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];

        return [
            'brand_professional_id' => (string) $professional->id,
            'brand_handle' => $professional->handle,
            'brand_name' => $professional->display_name,
            'shop_domain' => Arr::get($metadata, 'shop_domain'),
            'colors' => [
                'background' => $colors['background'] ?? null,
                'text' => $colors['text'] ?? null,
                'accent' => $colors['accent'] ?? null,
                'border' => $colors['border'] ?? null,
            ],
            'corner_radius' => $design['corner_radius'] ?? null,
            'border_thickness' => $design['border_thickness'] ?? null,
            'section_spacing' => $design['section_spacing'] ?? null,
            'logo' => [
                'full_url' => $logo['full_url'] ?? null,
                'square_url' => $logo['square_url'] ?? null,
            ],
            'slogan' => $design['slogan'] ?? null,
            'font_family' => is_string($design['font_family'] ?? null) && $design['font_family'] !== ''
                ? $design['font_family']
                : null,
            // Brand-uploaded placeholder images used by affiliate sitepages when
            // the affiliate hasn't supplied their own gallery yet. Stored at
            // settings.design.media.placeholder_sitepage_images — a flat list of
            // {name, path, url}. Only entries with a url are returned.
            'placeholder_sitepage_images' => $this->extractPlaceholderImages($media),
            'fallback_gallery' => $this->getFallbackGallery($site),
        ];
    }

    /**
     * Normalise the placeholder sitepage images stored on site.settings.design.media.
     *
     * @param  array<string, mixed>  $media
     * @return array<int, array{url: string, name: ?string, path: ?string}>
     */
    private function extractPlaceholderImages(array $media): array
    {
        $raw = is_array($media['placeholder_sitepage_images'] ?? null)
            ? $media['placeholder_sitepage_images']
            : [];

        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = is_string($item['url'] ?? null) ? $item['url'] : null;
            if (! $url) {
                continue;
            }
            $out[] = [
                'url' => $url,
                'name' => is_string($item['name'] ?? null) ? $item['name'] : null,
                'path' => is_string($item['path'] ?? null) ? $item['path'] : null,
            ];
        }

        return $out;
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
