<?php

namespace App\Services\Cache;

use App\Models\Core\ImageVariant;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Views\PublicSitePayload;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\Legal\ProfessionalLegalContentService;
use App\Services\Store\FeaturedProductsPayloadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SiteCacheService
{
    private const MISS_SENTINEL = '__MISS__';

    public function __construct(
        private readonly FeaturedProductsPayloadService $featuredProductsPayloads
    ) {}

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
                $cached = $this->ensureBlockCollections($cached);
                $cached = $this->ensureProfessionalType(
                    $cached,
                    (string) data_get($cached, 'professional.id', '')
                );
                $cached = $this->withStorePayload(
                    $cached,
                    (string) data_get($cached, 'professional.id', '')
                );

                if (! array_key_exists('legal', $cached)) {
                    $cached['legal'] = null;
                }

                // Always resolve image variant paths to URLs (handles pre-URL-resolution cache entries)
                $site = $cached['site'] ?? null;
                if (is_array($site)) {
                    $cached['site'] = $this->resolveImageVariantUrlsInSite($site, '');
                    $cached['site'] = $this->enrichSiteWithBrandPartnerRadius($cached['site']);
                }

                Cache::put($key, $cached, now()->addMinutes(15));
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
        if (is_array($payload) && !$this->hasRenderableLegalContent($payload)) {
            $payload = $this->backfillLegalContentPayload($row, $payload);
        }

        $services = is_array($payload['services'] ?? null)
            ? $payload['services']
            : $this->buildServicesPayload((string) ($row->professional_id ?? ''));

        $site = $payload['site'] ?? null;
        if (is_array($site)) {
            $site = $this->resolveImageVariantUrlsInSite($site, (string) ($row->site_id ?? ''));
            $site = $this->enrichSiteWithBrandPartnerRadius($site);
        }

        // Must match the controller response shape exactly.
        $links = is_array($payload['links'] ?? null) ? array_values($payload['links']) : [];
        $sections = is_array($payload['sections'] ?? null) ? array_values($payload['sections']) : [];
        $existingBlocks = is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : [];

        $data = [
            'published' => true,
            'site' => $site,
            'professional' => $payload['professional'] ?? null,
            'theme' => $payload['theme'] ?? null,
            'services' => $services,
            'links' => $links,
            'sections' => $sections,
            'blocks' => $this->buildCombinedBlocksPayload($links, $sections, $existingBlocks),
            'legal' => $payload['legal'] ?? null,
        ];
        $data = $this->ensureProfessionalType($data, (string) ($row->professional_id ?? ''));
        $data = $this->withStorePayload($data, (string) ($row->professional_id ?? ''));

        Cache::put($key, $data, now()->addMinutes(15));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    public function enrichSiteWithBrandPartnerRadius(array $site): array
    {
        $settings = is_array($site['settings'] ?? null) ? $site['settings'] : [];
        $brandPartner = is_array($settings['brand_partner'] ?? null)
            ? $settings['brand_partner']
            : (is_array($settings['brandPartner'] ?? null) ? $settings['brandPartner'] : []);

        $professionalId = $brandPartner['professional_id'] ?? $brandPartner['professionalId'] ?? null;
        if (! is_string($professionalId) || trim($professionalId) === '') {
            return $site;
        }

        $existingRadius = $brandPartner['border_radius'] ?? $brandPartner['borderRadius'] ?? null;
        if (is_string($existingRadius) && trim($existingRadius) !== '') {
            return $site;
        }

        $partnerSettings = Site::query()
            ->where('professional_id', $professionalId)
            ->value('settings');

        if (! is_array($partnerSettings)) {
            return $site;
        }

        $design = is_array($partnerSettings['design'] ?? null) ? $partnerSettings['design'] : [];
        $borderRadius = $design['border_radius'] ?? $design['borderRadius'] ?? null;
        if (! is_string($borderRadius) || trim($borderRadius) === '') {
            return $site;
        }

        $brandPartner['border_radius'] = $borderRadius;
        $settings['brand_partner'] = $brandPartner;
        $site['settings'] = $settings;

        return $site;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ensureBlockCollections(array $payload): array
    {
        $links = is_array($payload['links'] ?? null) ? array_values($payload['links']) : [];
        $sections = is_array($payload['sections'] ?? null) ? array_values($payload['sections']) : [];
        $existingBlocks = is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : [];

        if ($links === [] && $existingBlocks !== []) {
            $links = array_values(array_filter($existingBlocks, function ($block): bool {
                return is_array($block)
                    && strtolower((string) ($block['block_group'] ?? '')) === 'links';
            }));
        }

        if ($sections === [] && $existingBlocks !== []) {
            $sections = array_values(array_filter($existingBlocks, function ($block): bool {
                return is_array($block)
                    && strtolower((string) ($block['block_group'] ?? '')) === 'sections';
            }));
        }

        $payload['links'] = $links;
        $payload['sections'] = $sections;
        $payload['blocks'] = $this->buildCombinedBlocksPayload($links, $sections, $existingBlocks);

        return $payload;
    }

    /**
     * Ensure professional payload includes professional_type.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ensureProfessionalType(array $payload, string $professionalId): array
    {
        $professional = $payload['professional'] ?? null;
        if (! is_array($professional)) {
            return $payload;
        }

        $existing = $professional['professional_type'] ?? null;
        if (is_string($existing) && trim($existing) !== '') {
            return $payload;
        }

        if ($professionalId === '') {
            $professionalId = (string) ($professional['id'] ?? '');
        }

        $resolved = null;
        if ($professionalId !== '') {
            $resolved = Professional::query()
                ->where('id', $professionalId)
                ->value('professional_type');
        }

        $professional['professional_type'] = is_string($resolved) && trim($resolved) !== ''
            ? $resolved
            : 'barber';
        $payload['professional'] = $professional;

        return $payload;
    }

    /**
     * @param  array<int, mixed>  $links
     * @param  array<int, mixed>  $sections
     * @param  array<int, mixed>  $existingBlocks
     * @return array<int, array<string, mixed>>
     */
    private function buildCombinedBlocksPayload(array $links, array $sections, array $existingBlocks = []): array
    {
        if ($links === [] && $sections === []) {
            return array_values(array_filter($existingBlocks, fn ($block): bool => is_array($block)));
        }

        $normalizedLinks = array_map(function ($block): array {
            $data = is_array($block) ? $block : [];
            $data['block_group'] = 'links';
            return $data;
        }, $links);

        $normalizedSections = array_map(function ($block): array {
            $data = is_array($block) ? $block : [];
            $data['block_group'] = 'sections';
            return $data;
        }, $sections);

        $combined = array_merge($normalizedLinks, $normalizedSections);

        usort($combined, function (array $a, array $b): int {
            $aSort = (int) ($a['sort_order'] ?? PHP_INT_MAX);
            $bSort = (int) ($b['sort_order'] ?? PHP_INT_MAX);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            $aId = (string) ($a['id'] ?? '');
            $bId = (string) ($b['id'] ?? '');
            return $aId <=> $bId;
        });

        return array_values($combined);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withStorePayload(array $payload, string $professionalId): array
    {
        $siteSettings = [];
        if (is_array($payload['site'] ?? null)) {
            $siteSettingsRaw = $payload['site']['settings'] ?? [];
            if (is_array($siteSettingsRaw)) {
                $siteSettings = $siteSettingsRaw;
            }
        }

        $store = null;
        $existingStore = $payload['store'] ?? null;

        if (
            is_array($existingStore)
            && is_array($existingStore['selected_products'] ?? null)
            && array_key_exists('default_commission_rate', $existingStore)
            && array_key_exists('max_featured_products', $existingStore)
        ) {
            $store = [
                'selected_products' => array_values($existingStore['selected_products']),
                'default_commission_rate' => (float) $existingStore['default_commission_rate'],
                'max_featured_products' => (int) $existingStore['max_featured_products'],
            ];
        }

        if (
            $store === null
            && is_array($payload['selected_products'] ?? null)
            && array_key_exists('default_commission_rate', $payload)
            && array_key_exists('max_featured_products', $payload)
        ) {
            $store = [
                'selected_products' => array_values($payload['selected_products']),
                'default_commission_rate' => (float) $payload['default_commission_rate'],
                'max_featured_products' => (int) $payload['max_featured_products'],
            ];
        }

        if ($store === null) {
            $store = $this->featuredProductsPayloads->build(
                $professionalId,
                $siteSettings,
                'public_site_payload'
            );
        }

        $payload['store'] = $store;
        $payload['selected_products'] = $store['selected_products'];
        $payload['default_commission_rate'] = $store['default_commission_rate'];
        $payload['max_featured_products'] = $store['max_featured_products'];

        return $payload;
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

            usort($site[$key], function ($a, $b) {
                $aSort = is_array($a) ? (int) ($a['sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
                $bSort = is_array($b) ? (int) ($b['sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
                if ($aSort !== $bSort) {
                    return $aSort <=> $bSort;
                }

                $aId = is_array($a) ? (string) ($a['id'] ?? '') : '';
                $bId = is_array($b) ? (string) ($b['id'] ?? '') : '';
                return $aId <=> $bId;
            });
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
     * Backfill templated legal content for older professionals whose legal row has not been generated yet.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function backfillLegalContentPayload(PublicSitePayload $row, array $payload): array
    {
        $professionalId = (string) ($row->professional_id ?? '');
        if ($professionalId === '') {
            return $payload;
        }

        try {
            $professional = Professional::query()
                ->with('site')
                ->find($professionalId);

            if (!$professional || !$professional->site) {
                return $payload;
            }

            app(ProfessionalLegalContentService::class)->refreshGenerated($professional, $professional->site);

            $freshRow = PublicSitePayload::query()
                ->where('site_id', $row->site_id)
                ->first();

            $freshPayload = $freshRow?->payload;
            if (is_array($freshPayload) && $this->hasRenderableLegalContent($freshPayload)) {
                return $freshPayload;
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to backfill legal content for public payload.', [
                'site_id' => $row->site_id,
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasRenderableLegalContent(array $payload): bool
    {
        $legal = $payload['legal'] ?? null;

        if (!is_array($legal)) {
            return false;
        }

        $privacy = trim((string) ($legal['privacy_policy'] ?? ''));
        $terms = trim((string) ($legal['terms_and_conditions'] ?? ''));

        return $privacy !== '' && $terms !== '';
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
