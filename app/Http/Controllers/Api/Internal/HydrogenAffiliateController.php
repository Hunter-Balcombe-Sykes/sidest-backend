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

// V2: Internal endpoint for Hydrogen loaders. Validates an affiliate slug belongs to a brand and returns the affiliate record.
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

        // Find the brand by shop domain
        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('Brand not found.', 404);
        }

        // Find the affiliate by slug
        $affiliate = Professional::query()
            ->where('handle_lc', $slug)
            ->whereNull('deleted_at')
            ->first();

        if (! $affiliate || $affiliate->status !== 'active') {
            return $this->error('Affiliate not found.', 404);
        }

        // Verify affiliate is linked to this brand
        $linked = BrandPartnerLink::query()
            ->where('brand_professional_id', $integration->professional_id)
            ->where('affiliate_professional_id', $affiliate->id)
            ->exists();

        if (! $linked) {
            return $this->error('Affiliate not found.', 404);
        }

        $affiliateSite = Site::where('professional_id', $affiliate->id)->first();
        // Gallery images are gated by the gallery section block's publication
        // state (is_active). The per-image is_active + processing_state filter
        // inside getAffiliateGallery() is the second gate — together they
        // ensure Hydrogen only ever sees images the dashboard marked Live.
        $gallerySectionLive = $affiliateSite
            ? $this->isSectionLive((string) $affiliateSite->id, 'gallery')
            : false;
        $gallery = $gallerySectionLive ? $this->getAffiliateGallery($affiliateSite) : [];
        // Content pool images — affiliate's per-sitepage overrides that the
        // Hydrogen loader merges over the brand's default placeholders. Shape
        // matches the gallery payload so the Hydrogen side can read either
        // list through the same SitepageImage normaliser.
        $contentImages = $this->getAffiliateContent($affiliateSite);
        // Links are gated per-row via block.is_active (each has its own
        // Draft/Live toggle in the dashboard), so no section-level gate is
        // required here.
        $links = $this->getAffiliateLinks($affiliateSite);

        return $this->success([
            'affiliate_id' => (string) $affiliate->id,
            'name' => $affiliate->display_name,
            'slug' => $affiliate->handle,
            'has_gallery' => ! empty($gallery),
            'gallery' => $gallery,
            'content_images' => $contentImages,
            'links' => $links,
        ]);
    }

    /**
     * Returns affiliate services for the Hydrogen "Services & Pricing" section.
     * Used by manual mode affiliates whose services live in the Side St DB.
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
        $settings = is_array($site?->settings) ? $site->settings : [];
        $bookingMode = strtolower((string) ($settings['booking_mode'] ?? 'manual'));
        $manualBookingUrl = trim((string) ($settings['manual_booking_url'] ?? ''));

        $services = Service::query()
            ->with('category:id,title')
            ->where('professional_id', $affiliate->id)
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

        return $this->success([
            'booking_mode' => $bookingMode,
            'manual_booking_url' => $manualBookingUrl !== '' ? $manualBookingUrl : null,
            'services' => $services,
        ]);
    }

    private function getAffiliateGallery(?Site $site): array
    {
        return $this->getAffiliatePool($site, SiteMedia::POOL_GALLERY);
    }

    /**
     * Returns the affiliate's content-pool images — used by the Hydrogen
     * sitepage to override the brand's default placeholders position-for-position.
     */
    private function getAffiliateContent(?Site $site): array
    {
        return $this->getAffiliatePool($site, SiteMedia::POOL_CONTENT);
    }

    /**
     * Returns whether the given section block (e.g. 'gallery') is currently
     * Live on the site. The dashboard's section PublishSegmentedControl
     * writes is_active on the matching site.blocks row; missing rows are
     * treated as not-live so Hydrogen never sees content that was never
     * explicitly published.
     */
    private function isSectionLive(string $siteId, string $blockType): bool
    {
        return (bool) Block::query()
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', $blockType)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Returns the affiliate's live link blocks ({title, url} only). Each
     * link's dashboard Draft/Live toggle is its own is_active flag — there
     * is no section-level gate for links. Rows missing a title or url are
     * skipped so themes never see blanks.
     */
    private function getAffiliateLinks(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        return Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'links')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Block $block): array => [
                'title' => is_string($block->title) ? trim($block->title) : '',
                'url' => is_string($block->url) ? trim($block->url) : '',
            ])
            ->filter(fn (array $item) => $item['title'] !== '' && $item['url'] !== '')
            ->values()
            ->all();
    }

    /**
     * Shared lookup for an affiliate's site-media pool. Returns ordered items
     * with the optimised webp URL + alt text; skips rows without a resolvable
     * variant so callers never see null URLs.
     */
    private function getAffiliatePool(?Site $site, string $pool): array
    {
        if (! $site) {
            return [];
        }

        return SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', $pool)
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
