<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shopify\ThemeTokenParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

// V2: Internal endpoint that gives Hydrogen the resolved design token set for a brand.
// Merges provider_metadata.theme_tokens + provider_metadata.sitepage_overrides (override wins).
// Cached for 5 minutes — busted whenever theme tokens re-sync or overrides change.
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

        if ($payload === null) {
            return $this->error('Brand has no connected Shopify store.', 422);
        }

        return $this->success($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildDesignPayload(Professional $professional): ?array
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professional->id)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return null;
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $themeTokens = is_array($metadata['theme_tokens'] ?? null) ? $metadata['theme_tokens'] : [];
        $overrides = is_array($metadata['sitepage_overrides'] ?? null) ? $metadata['sitepage_overrides'] : [];

        $resolved = [];
        foreach (ThemeTokenParserService::TOKEN_KEYS as $key) {
            $resolved[$key] = $overrides[$key] ?? $themeTokens[$key] ?? null;
        }

        $site = Site::where('professional_id', $professional->id)->first();
        $siteSettings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($siteSettings['design'] ?? null) ? $siteSettings['design'] : [];
        $media = is_array($design['media'] ?? null) ? $design['media'] : [];

        return [
            'brand_professional_id' => (string) $professional->id,
            'brand_handle' => $professional->handle,
            'brand_name' => $professional->display_name,
            'shop_domain' => Arr::get($metadata, 'shop_domain'),
            'design_tokens' => $resolved,
            'logo_url' => is_string($media['brand_logo_url'] ?? null) ? $media['brand_logo_url'] : null,
            'fallback_gallery' => $this->getFallbackGallery($site),
            'synced_at' => $metadata['theme_tokens_synced_at'] ?? null,
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
