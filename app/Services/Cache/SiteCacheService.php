<?php

namespace App\Services\Cache;

use App\Models\Core\Site\Site;
use App\Models\Views\PublicSitePayload;
use Illuminate\Support\Facades\Cache;
use App\Models\Core\Site\SiteSubdomainAlias;

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
            return $cached;
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

        // Must match the controller response shape exactly.
        $data = [
            'published' => true,
            'site' => $payload['site'] ?? null,
            'professional' => $payload['professional'] ?? null,
            'theme' => $payload['theme'] ?? null,
            'links' => $payload['links'] ?? ($payload['blocks'] ?? []),
            'sections' => $payload['sections'] ?? [],
            'blocks' => $payload['blocks'] ?? ($payload['links'] ?? []),
        ];

        Cache::put($key, $data, now()->addMinutes(15));

        return $data;
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
            $keys[] = CacheKeyGenerator::publicSitePayload($aliasSubdomain);
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
