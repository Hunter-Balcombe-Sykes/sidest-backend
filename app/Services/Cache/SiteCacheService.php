<?php

namespace App\Services\Cache;

use App\Models\Core\ImageVariant;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Views\PublicSitePayload;
use App\Models\Core\Site\SiteSubdomainAlias;
use Illuminate\Support\Facades\Cache;

class SiteCacheService
{
    private const MISS_SENTINEL = '__MISS__';

    /**
     * Get public site payload (MOST CRITICAL - 95% of traffic)
     *
     * IMPORTANT: This must match the PublicSiteController response shape exactly.
     */
    public function getPublicSitePayload(string $subdomain): ?array
    {
        $subdomain = strtolower($subdomain);

        $key = CacheKeyGenerator::publicSitePayload($subdomain);
        $cached = Cache::get($key);

        if ($cached === self::MISS_SENTINEL) {
            return null;
        }
        if (is_array($cached)) {
            // Backward-compatible cache healing for payload shape changes.
            // Older cache entries may not include `services`.
            if (array_key_exists('services', $cached)) {
                // Always resolve image variant paths to URLs (handles pre-URL-resolution cache entries)
                $site = $cached['site'] ?? null;
                if (is_array($site)) {
                    $cached['site'] = $this->resolveImageVariantUrlsInSite($site, '');
                }
                return $cached;
            }
            Cache::forget($key);
        }

        $row = PublicSitePayload::query()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        // View only contains published sites; if not found, treat as 404.
        if (! $row) {
            // Negative-cache briefly to reduce a DB load from bot scans.
            Cache::put($key, self::MISS_SENTINEL, now()->addSeconds(30));
            return null;
        }

        $payload = $row->payload ?? [];
        $services = is_array($payload['services'] ?? null)
            ? $payload['services']
            : $this->buildServicesPayload((string) ($row->professional_id ?? ''));

        $site = $payload['site'] ?? null;
        if (is_array($site)) {
            $site = $this->resolveImageVariantUrlsInSite($site, (string) ($row->site_id ?? ''));
        }

        // Must match the controller response shape exactly.
        $data = [
            'published' => true,
            'site' => $site,
            'professional' => $payload['professional'] ?? null,
            'theme' => $payload['theme'] ?? null,
            'services' => $services,
            'links' => $payload['links'] ?? ($payload['blocks'] ?? []),
            'sections' => $payload['sections'] ?? [],
            'blocks' => $payload['blocks'] ?? ($payload['links'] ?? []),
        ];

        Cache::put($key, $data, now()->addMinutes(15));

        return $data;
    }

    /**
     * Resolve image variant paths to public URLs in the site payload.
     * The view returns storage paths; we need full URLs for the frontend.
     *
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function resolveImageVariantUrlsInSite(array $site, string $siteId): array
    {
        $imageIds = [];
        foreach (['gallery', 'content_images'] as $key) {
            $items = $site[$key] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item) && ! empty($item['id'])) {
                    $imageIds[] = $item['id'];
                }
            }
        }
        if ($imageIds === []) {
            return $site;
        }

        $variants = ImageVariant::query()
            ->whereIn('image_id', array_unique($imageIds))
            ->get();

        $urlByImageAndVariant = [];
        foreach ($variants as $v) {
            $urlByImageAndVariant[$v->image_id][$v->variant] = $v->url;
        }

        foreach (['gallery', 'content_images'] as $key) {
            $items = $site[$key] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $i => $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }
                $imageId = $item['id'];
                $pathVariants = $item['variants'] ?? [];
                if (! is_array($pathVariants)) {
                    continue;
                }
                $urlVariants = [];
                foreach ($pathVariants as $variantName => $path) {
                    $url = $urlByImageAndVariant[$imageId][$variantName] ?? null;
                    if ($url !== null) {
                        $urlVariants[$variantName] = $url;
                    }
                }
                $site[$key][$i]['variants'] = $urlVariants;
            }
        }

        return $site;
    }

    /**
     * Fallback builder for services when the public payload view is missing them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildServicesPayload(string $professionalId): array
    {
        if ($professionalId === '') {
            return [];
        }

        return Service::query()
            ->with('category:id,title')
            ->where('professional_id', $professionalId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'title' => $service->title,
                'description' => $service->description,
                'price_cents' => $service->price_cents,
                'currency_code' => $service->currency_code,
                'duration_minutes' => $service->duration_minutes,
                'is_active' => (bool) $service->is_active,
                'sort_order' => $service->sort_order,
                'category' => $service->category?->title ?? 'Services',
            ])
            ->values()
            ->all();
    }

    /**
     * Invalidate all cache keys for a site
     */
    public function invalidateSite(Site $site): void
    {
        $keys = [
            CacheKeyGenerator::publicSitePayload($site->subdomain),
            CacheKeyGenerator::siteBlocks($site->id, 'links'),
            CacheKeyGenerator::siteBlocks($site->id, 'sections'),
            CacheKeyGenerator::siteImages($site->id),
        ];

        // If subdomain changed, kill the OLD cache key too.
        // This is critical so old URLs redirect (via alias) instead of returning cached payload.
        if ($site->wasChanged('subdomain')) {
            $old = strtolower((string) $site->getOriginal('subdomain'));
            if ($old !== '') {
                $keys[] = CacheKeyGenerator::publicSitePayload($old);
            }
        }

        // Invalidate all alias cache keys (your Site model has no aliases() relation)
        $aliasSubdomains = SiteSubdomainAlias::query()
            ->where('site_id', $site->id)
            ->pluck('subdomain')
            ->all();

        foreach ($aliasSubdomains as $aliasSubdomain) {
            $keys[] = CacheKeyGenerator::publicSitePayload(strtoLower($aliasSubdomain));
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
    }

    public function getSiteLinkBlocks(string $siteId): array
    {
        return Cache::remember(
            CacheKeyGenerator::siteBlocks($siteId, 'links'),
            now()->addMinutes(15),
            fn () => Block::query()
                ->where('site_id', $siteId)
                ->where('block_group', 'links')
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->toArray()
        );
    }

    /**
     * Warm cache for a site (call after updates)
     */
    public function warmSiteCache(string $subdomain): void
    {
        $this->getPublicSitePayload($subdomain);
    }
}
