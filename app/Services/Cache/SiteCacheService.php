<?php

namespace App\Services\Cache;

use App\Models\Core\MediaVariant;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Views\PublicSitePayload;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Services\Branding\BrandFontResolver;
use App\Services\Legal\ProfessionalLegalContentService;
use App\Services\Store\FeaturedProductsPayloadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SiteCacheService
{
    private const MISS_SENTINEL = '__MISS__';

    /** @var array<string, array<string, string|null>|null> */
    private array $brandPartnerEnrichmentCache = [];

    public function __construct(
        private readonly FeaturedProductsPayloadService $featuredProductsPayloads,
        private readonly BrandFontResolver $brandFonts
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
                    $professionalId = (string) data_get($cached, 'professional.id', '');
                    $site = $this->hydrateSiteWithBrandTypography($site, $professionalId);
                    $site = $this->resolveImageVariantUrlsInSite($site, '');
                    $cached['site'] = $site;
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
            $site = $this->hydrateSiteWithBrandTypography($site, (string) ($row->professional_id ?? ''));
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
        return $this->brandFonts->hydrateTypographySettings($settings, $brandProfessionalId);
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
        $fontFileUrl = $this->brandFonts->activeFontUrl($professionalId);

        $resolved = [
            'username' => $this->normalizeString($partnerProfessional?->handle ?? null),
            'first_name' => $this->normalizeString($partnerProfessional?->first_name ?? null),
            'last_name' => $this->normalizeString($partnerProfessional?->last_name ?? null),
            'border_color' => $this->normalizeString($design['border_color'] ?? $design['borderColor'] ?? null),
            'border_radius' => $this->normalizeString($design['border_radius'] ?? $design['borderRadius'] ?? null),
            'border_width' => $this->normalizeString($design['border_width'] ?? $design['borderWidth'] ?? null),
            'general_spacing_padding' => $this->normalizeString($design['general_spacing_padding'] ?? $design['generalSpacingPadding'] ?? null),
            'font_file_url' => $this->normalizeString($fontFileUrl),
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
                $mediaId      = $item['id'];
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
                $byType  = $mvByMedia[$mediaId] ?? [];

                $site[$key][$i]['variants'] = $byType['mp4'] ?? [];
                $site[$key][$i]['streams']  = $byType['hls_playlist'] ?? [];
                $site[$key][$i]['poster']   = ($byType['poster']['poster'] ?? null);
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
        $site['gallery_videos']  = $site['gallery_videos']  ?? [];
        $site['content_videos']  = $site['content_videos']  ?? [];

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
        $professionalId = (string) ($site->professional_id ?? '');
        if ($professionalId !== '') {
            $this->brandFonts->forget($professionalId);
        }

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

            // Transitional fallback for environments where relational backfill has not run yet.
            if ($connectedSubdomains === []) {
                $connectedSubdomains = Site::query()
                    ->where(function ($query) use ($professionalId) {
                        $query
                            ->whereRaw("(settings->'brand_partner'->>'professional_id') = ?", [$professionalId])
                            ->orWhereRaw("(settings->'brandPartner'->>'professionalId') = ?", [$professionalId])
                            ->orWhereRaw("settings->'additional_brand_partners' @> ?", [json_encode([['professional_id' => $professionalId]])]);
                    })
                    ->pluck('subdomain')
                    ->filter(fn ($subdomain): bool => is_string($subdomain) && trim($subdomain) !== '')
                    ->map(fn ($subdomain): string => strtolower((string) $subdomain))
                    ->all();
            }

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
