<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// V2: Internal endpoint for Hydrogen loaders. Validates an affiliate slug belongs to
// a brand and returns the affiliate's full sitepage payload: gallery, content images,
// every link category (social + content + education + events + custom + synthesized
// booking), bio + about, document, newsletter, services, and booking.
//
// Every section that has a Draft/Live toggle in the dashboard is returned as an
// envelope `{state: "draft"|"live", data: <payload>|null}`. Hydrogen reads `state`
// to decide whether to mount the section; `data` is always null when draft so draft
// content never leaks publicly even if Hydrogen has a bug. Per-row Draft/Live (links)
// is handled server-side: is_active=false rows are simply filtered out.
class HydrogenAffiliateController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'shop_domain' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $shopDomain = strtolower(trim($validated['shop_domain']));
        $slug = strtolower(trim($validated['slug']));

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('Brand not found.', 404);
        }

        $affiliate = Professional::query()
            ->where('handle_lc', $slug)
            ->whereNull('deleted_at')
            ->first();

        if (! $affiliate || $affiliate->status !== 'active') {
            return $this->error('Affiliate not found.', 404);
        }

        $linked = BrandPartnerLink::query()
            ->where('brand_professional_id', $integration->professional_id)
            ->where('affiliate_professional_id', $affiliate->id)
            ->exists();

        if (! $linked) {
            return $this->error('Affiliate not found.', 404);
        }

        $site = Site::where('professional_id', $affiliate->id)->first();

        // Preload the site's section blocks once — every envelope helper reads
        // `is_active` off this collection instead of issuing one exists query
        // per section. Keyed by block_type for O(1) lookups.
        $sections = $site
            ? $site->sectionBlocks()->whereNull('deleted_at')->get()->keyBy('block_type')
            : collect();

        // Booking data is needed both as its own section and for synthesizing
        // a link into the links list, so compute it once and pass the result
        // into getAffiliateLinks().
        $booking = $this->getAffiliateBooking($site, $sections);

        // no-store: payload shape has evolved (e.g. links.id added in b9de807).
        // Prevent Oxygen/CDN from caching a stale shape across deploys.
        return $this->success([
            'affiliate_id' => (string) $affiliate->id,
            'name' => $affiliate->display_name,
            'slug' => $affiliate->handle,
            'gallery' => $this->getAffiliateGallery($site, $sections),
            // Content pool — no section-level gate in the dashboard, so no
            // envelope. Hydrogen merges these over brand defaults.
            'content_images' => $this->getAffiliateContent($site),
            'links' => $this->getAffiliateLinks($site, $booking),
            'bio' => $this->getAffiliateBio($affiliate, $sections),
            'document' => $this->getAffiliateDocument($site),
            'newsletter' => $this->getAffiliateNewsletter($sections),
            'services' => $this->getAffiliateServices($site, $affiliate->id, $sections),
            'booking' => $booking,
            // Shop has no content envelope (products come from Shopify), but
            // the block_id is needed so Hydrogen can fire click tracking when
            // the visitor opens the shop card. block_id is absent (not null)
            // when the block hasn't been created — Hydrogen guards on presence.
            'shop' => $this->sectionEnvelope($sections, 'shop', fn () => null),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Returns affiliate services for the Hydrogen "Services & Pricing" section.
     * Standalone endpoint kept for back-compat / lazy fetches; the same shape
     * also appears inside show() under the `services` envelope's `data`.
     */
    public function services(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'shop_domain' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $shopDomain = strtolower(trim($validated['shop_domain']));
        $slug = strtolower(trim($validated['slug']));

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('Brand not found.', 404);
        }

        $affiliate = Professional::query()
            ->where('handle_lc', $slug)
            ->whereNull('deleted_at')
            ->first();

        if (! $affiliate || $affiliate->status !== 'active') {
            return $this->error('Affiliate not found.', 404);
        }

        $linked = BrandPartnerLink::query()
            ->where('brand_professional_id', $integration->professional_id)
            ->where('affiliate_professional_id', $affiliate->id)
            ->exists();

        if (! $linked) {
            return $this->error('Affiliate not found.', 404);
        }

        $site = Site::where('professional_id', $affiliate->id)->first();

        return $this->success($this->buildServicesData($site, $affiliate->id));
    }

    // ── Section envelope helper ──────────────────────────────────────────────
    //
    // Shape: ['state' => 'draft'|'live', 'data' => mixed|null].
    // Pass the pre-loaded sections collection so we don't re-query per call.
    // If the section row doesn't exist (edge case during rollout) we treat it
    // as draft, matching the dashboard's "missing = not yet published" rule.

    /**
     * @param  \Illuminate\Support\Collection<string, Block>  $sections
     * @return array{state: string, block_id?: string, data: mixed}
     */
    private function sectionEnvelope($sections, string $blockType, callable $buildData): array
    {
        $section = $sections->get($blockType);
        $isLive = $section !== null && (bool) $section->is_active;

        $envelope = [
            'state' => $isLive ? 'live' : 'draft',
            'data' => $isLive ? $buildData($section) : null,
        ];

        // Omit block_id when the section row doesn't exist yet. Callers that
        // fire analytics events (e.g. shop click-tracking) can guard on key
        // presence rather than `block_id !== null`, which Hydrogen's pipeline
        // silently drops.
        if ($section !== null) {
            $envelope['block_id'] = (string) $section->id;
        }

        return $envelope;
    }

    // ── Gallery ──────────────────────────────────────────────────────────────

    /**
     * Returns the gallery envelope. When live, `data` is an ordered array of
     * `{url, alt_text, caption, kind, poster, duration_ms}` rows covering both
     * images and videos (the dashboard unifies them in one grid). Per-row filter
     * keeps only ready rows so Hydrogen never sees processing placeholders.
     *
     * @param  \Illuminate\Support\Collection<string, Block>  $sections
     * @return array{state: string, data: array|null}
     */
    private function getAffiliateGallery(?Site $site, $sections): array
    {
        return $this->sectionEnvelope($sections, 'gallery', function () use ($site): array {
            if (! $site) {
                return [];
            }

            return SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_GALLERY)
                ->where('is_active', true)
                ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
                ->with('mediaVariants')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (SiteMedia $media) => $this->buildGalleryItem($media))
                ->filter(fn (?array $item) => $item !== null && $item['url'] !== '')
                ->values()
                ->all();
        });
    }

    /**
     * Project a SiteMedia row to the Hydrogen gallery shape. Videos prefer the
     * HLS stream URL (plays natively in Safari + hls.js elsewhere) and fall
     * back to progressive MP4 variants; images use the optimised WebP.
     *
     * @return array{url: string, alt_text: string|null, caption: string|null, kind: string, poster: string|null, duration_ms: int|null}|null
     */
    private function buildGalleryItem(SiteMedia $media): ?array
    {
        $isVideo = $media->media_type === SiteMedia::MEDIA_TYPE_VIDEO;

        if ($isVideo) {
            $streams = [];
            $variants = [];
            $poster = null;
            foreach ($media->mediaVariants as $mv) {
                if ($mv->artifact_type === 'hls_playlist') {
                    $streams[$mv->variant_key] = $mv->url;
                } elseif ($mv->artifact_type === 'mp4') {
                    $variants[$mv->variant_key] = $mv->url;
                } elseif ($mv->artifact_type === 'poster') {
                    $poster = $mv->url;
                }
            }
            // Prefer HLS; fall back through progressive MP4 variants to any
            // remaining URL. Mirrors Sidest-Hydrogen's pickVideoSrc() order.
            $url = $streams['optimized']
                ?? $streams['maximized']
                ?? $variants['optimized']
                ?? $variants['maximized']
                ?? $variants['original']
                ?? '';

            return [
                'url' => $url,
                'alt_text' => $media->alt_text,
                'caption' => $media->caption,
                'kind' => 'video',
                'poster' => $poster,
                'duration_ms' => $media->duration_ms,
            ];
        }

        $variantUrls = $media->variantUrls();
        $url = $variantUrls['optimized'] ?? $variantUrls['original'] ?? '';

        return [
            'url' => $url,
            'alt_text' => $media->alt_text,
            'caption' => $media->caption,
            'kind' => 'image',
            'poster' => null,
            'duration_ms' => null,
        ];
    }

    // ── Content images ───────────────────────────────────────────────────────

    private function getAffiliateContent(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        return SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_CONTENT)
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

    // ── Links (all categories + synthesized booking) ─────────────────────────

    /**
     * Returns every live link block the affiliate has, across every category
     * (social, content, education, events, custom). Each link is tagged with
     * `category` and, for platform-tagged rows, `platform`. Booking is not a
     * link block on the dashboard side, but Hydrogen wants to render it
     * alongside the other links — when the booking section is live, we
     * synthesize a `{category: 'booking'}` row at the end of the list.
     *
     * @param  array{state: string, data: array|null}  $bookingEnvelope
     */
    private function getAffiliateLinks(?Site $site, array $bookingEnvelope): array
    {
        if (! $site) {
            return [];
        }

        $rows = Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'links')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get()
            ->map(function (Block $block): array {
                $settings = is_array($block->settings) ? $block->settings : [];
                $platform = is_string($settings['platform'] ?? null)
                    ? strtolower(trim((string) $settings['platform']))
                    : null;
                $platform = $platform !== '' ? $platform : null;
                // Category lives in settings to avoid a schema change for
                // what is effectively a free-form tag. Default to 'custom'
                // for older rows that pre-date the category field.
                $category = is_string($settings['category'] ?? null)
                    ? strtolower(trim((string) $settings['category']))
                    : 'custom';

                $title = is_string($block->title) ? trim($block->title) : '';
                $url = is_string($block->url) ? trim($block->url) : '';

                // Resilience: older link rows can have empty title/url at rest
                // — settings.platform + settings.handle are the source of truth
                // there. Rebuild both from the platform config so the row still
                // renders on Hydrogen without requiring a backfill migration.
                if (($title === '' || $url === '') && $platform !== null) {
                    $config = config("partna.social_platforms.{$platform}");
                    $handle = is_string($settings['handle'] ?? null)
                        ? trim((string) $settings['handle'])
                        : '';
                    if (is_array($config)) {
                        if ($title === '' && is_string($config['display_name'] ?? null)) {
                            $title = (string) $config['display_name'];
                        }
                        if ($url === '' && $handle !== '' && is_string($config['url_template'] ?? null)) {
                            $url = str_replace('{handle}', $handle, (string) $config['url_template']);
                        }
                    }
                }

                return [
                    'id' => (string) $block->id,
                    'title' => $title,
                    'url' => $url,
                    'category' => $category !== '' ? $category : 'custom',
                    'platform' => $platform,
                ];
            })
            ->filter(fn (array $item) => $item['title'] !== '' && $item['url'] !== '')
            ->values()
            ->all();

        // Append the synthesized booking link when booking is live + resolved.
        // Keeping this controller-side means themes have a single list to
        // render rather than special-casing booking everywhere.
        if ($bookingEnvelope['state'] === 'live' && is_array($bookingEnvelope['data'])) {
            $rows[] = [
                'title' => (string) ($bookingEnvelope['data']['title'] ?? 'Book now'),
                'url' => (string) ($bookingEnvelope['data']['resolved_url'] ?? ''),
                'category' => 'booking',
                'platform' => is_string($bookingEnvelope['data']['platform'] ?? null)
                    ? $bookingEnvelope['data']['platform']
                    : null,
            ];
            // Filter out the booking link if its url ended up empty.
            $rows = array_values(array_filter(
                $rows,
                fn (array $item) => $item['url'] !== '',
            ));
        }

        return $rows;
    }

    // ── Bio + credentials + experience ───────────────────────────────────────

    /**
     * @param  \Illuminate\Support\Collection<string, Block>  $sections
     * @return array{state: string, data: array|null}
     */
    private function getAffiliateBio(Professional $affiliate, $sections): array
    {
        return $this->sectionEnvelope($sections, 'bio', function () use ($affiliate): array {
            $about = is_array($affiliate->about) ? $affiliate->about : [];
            $credentials = is_array($about['credentials'] ?? null) ? $about['credentials'] : [];
            $experience = is_array($about['experience'] ?? null) ? $about['experience'] : [];

            return [
                'text' => (string) ($affiliate->bio ?? ''),
                'credentials' => array_values(array_filter(array_map(
                    fn ($row) => $this->normaliseCredential($row),
                    $credentials,
                ))),
                'experience' => array_values(array_filter(array_map(
                    fn ($row) => $this->normaliseExperience($row),
                    $experience,
                ))),
            ];
        });
    }

    /**
     * Normalise a credential row from the about JSONB column. The dashboard
     * sometimes stamps client-side UUIDs; we strip them so the wire shape is
     * stable for Hydrogen.
     */
    private function normaliseCredential($row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        return [
            'title' => $title,
            'issuer' => trim((string) ($row['issuer'] ?? '')),
            'year' => isset($row['year']) && $row['year'] !== '' ? (string) $row['year'] : null,
        ];
    }

    /**
     * Dashboard writes experience as {role, place, start, end, description}.
     * Project to the Hydrogen contract {title, organisation, period} so the
     * wire shape mirrors credentials and the theme layer doesn't have to know
     * about the source schema differences.
     */
    private function normaliseExperience($row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        // Accept both the current dashboard schema (role/place) and the older
        // Hydrogen contract names (title/organisation) so pre-migration rows
        // don't silently drop.
        $title = trim((string) ($row['role'] ?? $row['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $organisation = trim((string) (
            $row['place']
            ?? $row['organisation']
            ?? $row['organization']
            ?? ''
        ));

        $start = isset($row['start']) && $row['start'] !== '' ? (string) $row['start'] : null;
        // A null `end` means "currently active" per the dashboard's
        // ExperienceEditorModal contract — render as "Current" so the public
        // site reads like a CV, not a blank field.
        $rawEnd = $row['end'] ?? null;
        $end = is_string($rawEnd) && $rawEnd !== '' ? $rawEnd : null;

        if ($start !== null) {
            $period = $start.' – '.($end ?? 'Current');
        } elseif (isset($row['period']) && $row['period'] !== '') {
            $period = (string) $row['period'];
        } else {
            $period = null;
        }

        return [
            'title' => $title,
            'organisation' => $organisation,
            'period' => $period,
        ];
    }

    // ── Document ─────────────────────────────────────────────────────────────

    /**
     * Document is gated per-row (SiteMedia.is_active), not at the section
     * level — the dashboard's Draft/Live toggle writes is_enabled which maps
     * directly to SiteMedia.is_active, and there is no separate "documents"
     * section block that also needs flipping. If a ready + active row exists
     * the envelope is 'live', otherwise 'draft'.
     *
     * @return array{state: string, data: array|null}
     */
    private function getAffiliateDocument(?Site $site): array
    {
        $media = $site
            ? SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DOCUMENTS)
                ->where('is_active', true)
                ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
                ->orderByDesc('created_at')
                ->first()
            : null;

        if (! $media) {
            return ['state' => 'draft', 'data' => null];
        }

        return [
            'state' => 'live',
            'data' => [
                // Raw id — Hydrogen uses it to build a same-origin proxy URL
                // (e.g. /api/document/{id}) so react-pdf can fetch bytes
                // without tripping CORS. The absolute download_url below is
                // still used by the Download button, which triggers a
                // browser navigation and is not subject to CORS.
                'id' => (string) $media->id,
                'title' => $media->alt_text,
                'caption' => $media->caption,
                // Absolute URL to the backend download redirect (signed R2
                // URL issued server-side) so the Hydrogen payload never
                // carries R2 credentials.
                'download_url' => url('/api/public/documents/'.$media->id.'/download'),
                'mime' => $media->original_mime,
                'size_bytes' => $media->original_size_bytes,
            ],
        ];
    }

    // ── Newsletter ───────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Support\Collection<string, Block>  $sections
     * @return array{state: string, data: array|null}
     */
    private function getAffiliateNewsletter($sections): array
    {
        return $this->sectionEnvelope($sections, 'newsletter', function (Block $section): array {
            $settings = is_array($section->settings) ? $section->settings : [];

            return [
                'headline' => (string) ($settings['headline'] ?? ''),
                'description' => (string) ($settings['description'] ?? ''),
                'cta_label' => (string) ($settings['cta_label'] ?? ''),
            ];
        });
    }

    // ── Services ─────────────────────────────────────────────────────────────

    /**
     * @param  \Illuminate\Support\Collection<string, Block>  $sections
     * @return array{state: string, data: array|null}
     */
    private function getAffiliateServices(?Site $site, string $affiliateId, $sections): array
    {
        return $this->sectionEnvelope($sections, 'services', function () use ($site, $affiliateId) {
            return $this->buildServicesData($site, $affiliateId);
        });
    }

    /**
     * Builds the services payload shared by the envelope and the standalone
     * /affiliate-services endpoint. Keeping them in one place means the two
     * consumers can never drift.
     */
    private function buildServicesData(?Site $site, string $affiliateId): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        $bookingMode = strtolower((string) ($settings['booking_mode'] ?? 'manual'));
        $manualBookingUrl = trim((string) ($settings['manual_booking_url'] ?? ''));

        $services = Service::query()
            ->with('category:id,title')
            ->where('professional_id', $affiliateId)
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
                'category' => $service->category?->title ?? 'Services',
            ])
            ->values()
            ->all();

        return [
            'booking_mode' => $bookingMode,
            'manual_booking_url' => $manualBookingUrl !== '' ? $manualBookingUrl : null,
            'services' => $services,
        ];
    }

    // ── Booking ──────────────────────────────────────────────────────────────

    /**
     * Booking data lives on the `booking` section block's settings — the
     * section-level toggle is the single gate (no separate booking section
     * visibility state to reconcile). When live + a booking URL is set,
     * returns the resolved URL; otherwise data is null.
     *
     * @param  \Illuminate\Support\Collection<string, Block>  $sections
     * @return array{state: string, data: array|null}
     */
    private function getAffiliateBooking(?Site $site, $sections): array
    {
        return $this->sectionEnvelope($sections, 'booking', function (Block $section): ?array {
            $settings = is_array($section->settings) ? $section->settings : [];
            $bookingUrl = trim((string) ($settings['booking_url'] ?? ''));

            // Platform is stored next to booking_url when known (calendly, acuity,
            // etc.) so themes can pick a wordmark; missing platform = generic "book now" CTA.
            $platform = is_string($settings['platform'] ?? null)
                ? strtolower(trim((string) $settings['platform']))
                : null;

            // Title override — affiliate may want "Book a consult" instead of "Book now".
            $title = is_string($settings['title'] ?? null)
                ? trim((string) $settings['title'])
                : '';

            if ($bookingUrl === '') {
                return null;
            }

            return [
                'platform' => $platform !== '' ? $platform : null,
                'path' => $bookingUrl,
                // Backend currently stores the fully-resolved URL in settings.booking_url
                // (the dashboard normalises before save). If/when we switch to storing
                // platform+handle separately, resolve here.
                'resolved_url' => $bookingUrl,
                'title' => $title !== '' ? $title : 'Book now',
            ];
        });
    }
}
