<?php

namespace App\Services\Cache;

use App\Models\Core\MediaVariant;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Views\PublicSitePayload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// V2: Public site payload caching with single-flight locking (prevents thundering herd). Handles 95% of traffic. Simplified in V2 — no more product payload caching.
class SiteCacheService
{
    private const MISS_SENTINEL = '__MISS__';

    /** @var array<string, array<string, string|null>|null> */
    private array $brandPartnerEnrichmentCache = [];

    public function __construct() {}

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
                $cached = $this->safeWithStorePayload(
                    $cached,
                    (string) data_get($cached, 'professional.id', ''),
                    $subdomain
                );

                if (! array_key_exists('legal', $cached)) {
                    $cached['legal'] = null;
                }

                // Always resolve image variant paths to URLs (handles pre-URL-resolution cache entries)
                $site = $cached['site'] ?? null;
                if (is_array($site)) {
                    $professionalId = (string) data_get($cached, 'professional.id', '');
                    $cached['site'] = $this->safeHydrateSitePayload(
                        $site,
                        $professionalId,
                        '',
                        $subdomain
                    );
                }

                Cache::put($key, $cached, now()->addMinutes(15));

                return $cached;
            }
            Cache::forget($key);
        }

        // Cache miss — acquire a per-subdomain fill lock so only one process rebuilds
        // the payload from the DB view while concurrent requests wait (single-flight).
        $fillLock = Cache::lock('site:fill:'.$subdomain, 10);

        try {
            // Block up to 5 s for the lock; raises LockTimeoutException if it can't.
            $fillLock->block(5);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // Another process is (or was) filling the cache.
            // Return whatever is now in cache, or null if it's still a miss.
            $warm = Cache::get($key);
            if ($warm === self::MISS_SENTINEL) {
                return null;
            }

            return is_array($warm) ? $warm : null;
        }

        try {
            // Double-check: the lock winner may have filled the cache while we waited.
            $rechecked = Cache::get($key);
            if ($rechecked === self::MISS_SENTINEL) {
                return null;
            }
            if (is_array($rechecked) && array_key_exists('services', $rechecked)) {
                return $rechecked;
            }

            $row = PublicSitePayload::query()
                ->whereRaw('lower(subdomain) = ?', [$subdomain])
                ->first();

            // View only contains published sites; if not found, treat as 404.
            if (! $row) {
                // Negative-cache briefly to reduce DB load from bot scans.
                Cache::put($key, self::MISS_SENTINEL, now()->addSeconds(30));

                return null;
            }

            $payload = $row->payload ?? [];

            $services = is_array($payload['services'] ?? null)
                ? $payload['services']
                : $this->buildServicesPayload((string) ($row->professional_id ?? ''));

            $site = $payload['site'] ?? null;
            if (is_array($site)) {
                $site = $this->safeHydrateSitePayload(
                    $site,
                    (string) ($row->professional_id ?? ''),
                    (string) ($row->site_id ?? ''),
                    $subdomain
                );
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
                // Preserve the store sub-object from the source payload so that
                // withStorePayload() below can lift selected_products + commission
                // settings out of payload.store. Without this, the rebuilt $data
                // has no 'store' key and withStorePayload falls through to empty
                // defaults — the front-end would see no featured products even
                // though the view row contains them.
                'store' => $payload['store'] ?? null,
            ];
            $data = $this->ensureProfessionalType($data, (string) ($row->professional_id ?? ''));
            $data = $this->safeWithStorePayload($data, (string) ($row->professional_id ?? ''), $subdomain);
            $data = $this->safeApplyBrandImageFallbacks($data, $subdomain);

            Cache::put($key, $data, now()->addMinutes(15));

            return $data;
        } finally {
            $fillLock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyBrandImageFallbacks(array $payload): array
    {
        $professional = $payload['professional'] ?? null;
        if (! is_array($professional) || ($professional['professional_type'] ?? null) === 'brand') {
            return $payload;
        }

        $brandPartner = $payload['site']['settings']['brand_partner'] ?? null;
        if (! is_array($brandPartner) || empty($brandPartner['professional_id'])) {
            return $payload;
        }

        $brandId = $brandPartner['professional_id'];
        $brandSite = Site::query()
            ->where('professional_id', $brandId)
            ->first();

        if (! $brandSite) {
            return $payload;
        }

        // Brand placeholders now live in site_media (pool=design, purpose=placeholder).
        // The service resolves variant URLs; we project them to { url, alt_text }
        // to match the new Hydrogen brand-design response shape.
        $designMedia = app(\App\Services\Media\BrandDesignMediaService::class)
            ->listDesignMedia((string) $brandSite->id);

        if (empty($designMedia['placeholders'])) {
            return $payload;
        }

        $placeholderImages = array_map(
            fn (array $p) => [
                'url' => $p['url'],
                'alt_text' => $p['alt_text'],
            ],
            $designMedia['placeholders']
        );

        $imageKeys = ['gallery', 'content_images'];

        foreach ($imageKeys as $key) {
            if (! isset($payload['site'][$key]) || ! is_array($payload['site'][$key])) {
                continue;
            }

            if (empty($payload['site'][$key])) {
                $payload['site'][$key] = $placeholderImages;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function safeHydrateSitePayload(array $site, string $professionalId, string $siteId, string $subdomain): array
    {
        try {
            $site = $this->hydrateSiteWithBrandTypography($site, $professionalId);
            $site = $this->resolveImageVariantUrlsInSite($site, $siteId);

            return $this->enrichSiteWithBrandPartnerRadius($site);
        } catch (\Throwable $e) {
            Log::warning('Public site payload hydration failed; returning base site payload.', [
                'subdomain' => $subdomain,
                'professional_id' => $professionalId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);

            return $site;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeWithStorePayload(array $payload, string $professionalId, string $subdomain): array
    {
        try {
            return $this->withStorePayload($payload, $professionalId);
        } catch (\Throwable $e) {
            Log::warning('Public site store payload build failed; returning payload without refreshed store data.', [
                'subdomain' => $subdomain,
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeApplyBrandImageFallbacks(array $payload, string $subdomain): array
    {
        try {
            return $this->applyBrandImageFallbacks($payload);
        } catch (\Throwable $e) {
            Log::warning('Brand image fallback enrichment failed; returning payload unchanged.', [
                'subdomain' => $subdomain,
                'professional_id' => (string) data_get($payload, 'professional.id', ''),
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    public function hydrateSiteWithBrandTypography(array $site, string $brandProfessionalId): array
    {
        $settings = is_array($site['settings'] ?? null) ? $site['settings'] : [];
        $site['settings'] = $this->hydrateTypographySettings($settings, $brandProfessionalId);

        return $site;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function hydrateTypographySettings(array $settings, string $brandProfessionalId): array
    {
        return $settings;
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

        $enrichment = $this->resolveBrandPartnerEnrichmentData(trim($professionalId));
        if (! is_array($enrichment)) {
            return $site;
        }

        if ($this->isMissingBrandPartnerField($brandPartner, 'border_radius', 'borderRadius') && $this->isFilledString($enrichment['border_radius'] ?? null)) {
            $brandPartner['border_radius'] = $enrichment['border_radius'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'font_file_url', 'fontFileUrl') && $this->isFilledString($enrichment['font_file_url'] ?? null)) {
            $brandPartner['font_file_url'] = $enrichment['font_file_url'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'username', 'handle') && $this->isFilledString($enrichment['username'] ?? null)) {
            $brandPartner['username'] = $enrichment['username'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'first_name', 'firstName') && $this->isFilledString($enrichment['first_name'] ?? null)) {
            $brandPartner['first_name'] = $enrichment['first_name'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'last_name', 'lastName') && $this->isFilledString($enrichment['last_name'] ?? null)) {
            $brandPartner['last_name'] = $enrichment['last_name'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'border_color', 'borderColor') && $this->isFilledString($enrichment['border_color'] ?? null)) {
            $brandPartner['border_color'] = $enrichment['border_color'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'logo_letter_spacing', 'logoLetterSpacing') && $this->isFilledString($enrichment['logo_letter_spacing'] ?? null)) {
            $brandPartner['logo_letter_spacing'] = $enrichment['logo_letter_spacing'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'logo_font_size', 'logoFontSize') && $this->isFilledString($enrichment['logo_font_size'] ?? null)) {
            $brandPartner['logo_font_size'] = $enrichment['logo_font_size'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'border_width', 'borderWidth') && $this->isFilledString($enrichment['border_width'] ?? null)) {
            $brandPartner['border_width'] = $enrichment['border_width'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'general_spacing_padding', 'generalSpacingPadding') && $this->isFilledString($enrichment['general_spacing_padding'] ?? null)) {
            $brandPartner['general_spacing_padding'] = $enrichment['general_spacing_padding'];
        }

        $settings['brand_partner'] = $brandPartner;
        $site['settings'] = $settings;

        return $site;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function resolveBrandPartnerEnrichmentData(string $professionalId): ?array
    {
        if (array_key_exists($professionalId, $this->brandPartnerEnrichmentCache)) {
            return $this->brandPartnerEnrichmentCache[$professionalId];
        }

        $partnerSite = Site::query()
            ->where('professional_id', $professionalId)
            ->first(['settings']);

        $partnerProfessional = Professional::query()
            ->whereKey($professionalId)
            ->first(['handle', 'first_name', 'last_name']);

        if (! $partnerSite && ! $partnerProfessional) {
            $this->brandPartnerEnrichmentCache[$professionalId] = null;

            return null;
        }

        $partnerSettings = is_array($partnerSite?->settings ?? null) ? $partnerSite->settings : [];
        $design = is_array($partnerSettings['design'] ?? null) ? $partnerSettings['design'] : [];
        $typography = is_array($design['typography'] ?? null) ? $design['typography'] : [];
        $resolved = [
            'username' => $this->normalizeString($partnerProfessional?->handle ?? null),
            'first_name' => $this->normalizeString($partnerProfessional?->first_name ?? null),
            'last_name' => $this->normalizeString($partnerProfessional?->last_name ?? null),
            'border_color' => $this->normalizeString($design['border_color'] ?? $design['borderColor'] ?? null),
            'border_radius' => $this->normalizeString($design['border_radius'] ?? $design['borderRadius'] ?? null),
            'border_width' => $this->normalizeString($design['border_width'] ?? $design['borderWidth'] ?? null),
            'general_spacing_padding' => $this->normalizeString($design['general_spacing_padding'] ?? $design['generalSpacingPadding'] ?? null),
            'font_file_url' => null,
            'logo_letter_spacing' => $this->normalizeString($typography['logo_letter_spacing'] ?? $typography['logoLetterSpacing'] ?? null),
            'logo_font_size' => $this->normalizeString($typography['logo_font_size'] ?? $typography['logoFontSize'] ?? null),
        ];

        $this->brandPartnerEnrichmentCache[$professionalId] = $resolved;

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $brandPartner
     */
    private function isMissingBrandPartnerField(array $brandPartner, string $snakeKey, string $camelKey): bool
    {
        return ! $this->isFilledString($brandPartner[$snakeKey] ?? null)
            && ! $this->isFilledString($brandPartner[$camelKey] ?? null);
    }

    private function isFilledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
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
            : 'professional';
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
                'checkout_mode' => in_array(($existingStore['checkout_mode'] ?? null), ['shopify', 'stripe'], true)
                    ? $existingStore['checkout_mode']
                    : 'shopify',
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
                'checkout_mode' => in_array(($payload['checkout_mode'] ?? null), ['shopify', 'stripe'], true)
                    ? $payload['checkout_mode']
                    : 'shopify',
            ];
        }

        if ($store === null) {
            $store = [
                'selected_products' => [],
                'default_commission_rate' => (float) config('sidest.store.default_commission_rate', 15),
                'max_featured_products' => (int) config('sidest.store.max_featured_products', 12),
                'checkout_mode' => 'shopify',
            ];
        }

        $payload['store'] = $store;
        $payload['selected_products'] = $store['selected_products'];
        $payload['default_commission_rate'] = $store['default_commission_rate'];
        $payload['max_featured_products'] = $store['max_featured_products'];
        $payload['checkout_mode'] = $store['checkout_mode'] ?? 'shopify';

        return $payload;
    }

    /**
     * Resolve image variant paths to public URLs in the site payload.
     * The view returns storage paths; we need full URLs for the frontend.
     * Also resolves video variant/stream/poster paths in gallery_videos and content_videos.
     *
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function resolveImageVariantUrlsInSite(array $site, string $siteId): array
    {
        // --- Collect all media IDs (images + videos) in one pass ---
        $allMediaIds = [];
        foreach (['gallery', 'content_images', 'gallery_videos', 'content_videos'] as $key) {
            foreach ($site[$key] ?? [] as $item) {
                if (is_array($item) && ! empty($item['id'])) {
                    $allMediaIds[] = $item['id'];
                }
            }
        }

        // Single query: one DB round-trip for all variant rows across all media types.
        // Index as: media_id → artifact_type → variant_key → URL
        $mvByMedia = [];
        if ($allMediaIds !== []) {
            $allVariants = MediaVariant::query()
                ->whereIn('media_id', array_unique($allMediaIds))
                ->get();

            foreach ($allVariants as $mv) {
                $mvByMedia[$mv->media_id][$mv->artifact_type][$mv->variant_key] = $mv->url;
            }
        }

        // --- Images: resolve variant URLs ---
        foreach (['gallery', 'content_images'] as $key) {
            $items = $site[$key] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $i => $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }
                $mediaId = $item['id'];
                $pathVariants = $item['variants'] ?? [];
                if (! is_array($pathVariants)) {
                    continue;
                }
                $urlVariants = [];
                foreach ($pathVariants as $variantKey => $path) {
                    $url = $mvByMedia[$mediaId]['webp'][$variantKey] ?? null;
                    if ($url !== null) {
                        $urlVariants[$variantKey] = $url;
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

        // --- Videos: resolve variant/stream/poster URLs ---
        foreach (['gallery_videos', 'content_videos'] as $key) {
            $items = $site[$key] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $i => $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }
                $mediaId = $item['id'];
                $byType = $mvByMedia[$mediaId] ?? [];

                $site[$key][$i]['variants'] = $byType['mp4'] ?? [];
                $site[$key][$i]['streams'] = $byType['hls_playlist'] ?? [];
                $site[$key][$i]['poster'] = ($byType['poster']['poster'] ?? null);
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

        // Ensure video keys always exist even when there are no videos (backward-compat).
        $site['gallery_videos'] = $site['gallery_videos'] ?? [];
        $site['content_videos'] = $site['content_videos'] ?? [];

        // --- Document: resolve preview_url from storage path to full CDN URL ---
        if (isset($site['document']) && is_array($site['document']) && ! empty($site['document']['preview_url'])) {
            $rawPath = (string) $site['document']['preview_url'];
            $site['document']['preview_url'] = Storage::disk(config('sidest.media_disk'))->url($rawPath);
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
     * Bust the Hydrogen brand-design cache for a site. Call from every write
     * path that touches design tokens or design media (logo, placeholders).
     *
     * Why explicit per-action: the stale window is 5s, so a forgotten bust
     * isn't catastrophic — but the user sees their own saves on Hydrogen
     * immediately only when every write path fires this.
     */
    public function forgetBrandDesign(string $siteId): void
    {
        Cache::forget(CacheKeyGenerator::hydrogenBrandDesign($siteId));
    }

    /**
     * Invalidate all cache keys for a site
     */
    public function invalidateSite(Site $site): void
    {
        $professionalId = (string) ($site->professional_id ?? '');

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
            $keys[] = CacheKeyGenerator::publicSitePayload(strtolower($aliasSubdomain));
        }

        if ($professionalId !== '') {
            $connectedProfessionalIds = BrandPartnerLink::query()
                ->where('brand_professional_id', $professionalId)
                ->pluck('affiliate_professional_id')
                ->all();

            $connectedSubdomains = Site::query()
                ->whereIn('professional_id', $connectedProfessionalIds)
                ->pluck('subdomain')
                ->filter(fn ($subdomain): bool => is_string($subdomain) && trim($subdomain) !== '')
                ->map(fn ($subdomain): string => strtolower((string) $subdomain))
                ->all();

            foreach ($connectedSubdomains as $connectedSubdomain) {
                $keys[] = CacheKeyGenerator::publicSitePayload($connectedSubdomain);
            }
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
