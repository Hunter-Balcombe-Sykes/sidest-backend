<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSiteFromRequest;
use App\Http\Requests\Api\PublicSite\Analytics\CartEventRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ClickRequest;
use App\Http\Requests\Api\PublicSite\Analytics\PageviewRequest;
use App\Models\Analytics\CartEvent;
use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\Core\Site\Block;
use App\Services\Cache\AnalyticsCacheService;
use Illuminate\Http\JsonResponse;
use Throwable;

// Records pageview and click analytics events from public mini-sites.
// Read path queries raw site_visits + link_clicks tables directly; cache invalidation
// bumps a per-professional version token so dashboards see fresh totals on next read.
class AnalyticsController extends ApiController
{
    use DetectsClientInfo;
    use HashesClientData;
    use ResolvesSiteFromRequest;

    public function __construct(
        private AnalyticsCacheService $analyticsCache
    ) {}

    public function pageview(PageviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve site by ID or subdomain
        $site = $this->resolveSiteFromData($data);

        if (! $site) {
            // 422 when site_id was given but failed the subdomain cross-check (IDOR attempt).
            // 404 when only a subdomain was given and simply wasn't found.
            $statusCode = ! empty($data['site_id']) ? 422 : 404;

            return $this->error('Site not found', $statusCode);
        }

        // 404 not 403: public endpoint — returning 403 would reveal the site exists but is unpublished
        if (! $site->is_published) {
            return $this->error('Site not found', 404);
        }

        // Create pageview record
        $visit = new SiteVisit([
            'occurred_at' => now(),
            'session_id' => $data['session_id'] ?? null,
            'visitor_id' => $data['visitor_id'] ?? null,
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => $request->userAgent(),
            'referrer' => $data['referrer'] ?? $request->headers->get('referer'),
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'country_code' => $this->detectCountryCode($request),
            'device_type' => $this->detectDeviceType($request->userAgent()),
        ]);
        $visit->professional_id = $site->professional_id;
        $visit->site_id = $site->id;
        $visit->save();

        try {
            $this->analyticsCache->invalidateAnalytics($site->professional_id);
        } catch (Throwable) {
        }

        return $this->success([
            'message' => 'Pageview recorded',
            'visit_id' => $visit->id,
        ], 201);
    }

    public function click(ClickRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve site by ID or subdomain
        $site = $this->resolveSiteFromData($data);

        if (! $site) {
            // 422 when site_id was given but failed the subdomain cross-check (IDOR attempt).
            // 404 when only a subdomain was given and simply wasn't found.
            $statusCode = ! empty($data['site_id']) ? 422 : 404;

            return $this->error('Site not found', $statusCode);
        }

        // 404 not 403: public endpoint — returning 403 would reveal the site exists but is unpublished
        if (! $site->is_published) {
            return $this->error('Site not found', 404);
        }

        // Discard bot traffic silently — fake 200 avoids fingerprinting the filter
        if ($this->isBotUserAgent($request->userAgent())) {
            return $this->success(['message' => 'Click recorded'], 200);
        }

        // Verify block exists and belongs to this site
        $block = Block::where('id', $data['block_id'])
            ->where('site_id', $site->id)
            ->first();

        if (! $block) {
            return $this->error('Block not found or does not belong to this site', 404);
        }

        $blockGroup = strtolower((string) $block->block_group);
        $blockType = strtolower((string) $block->block_type);
        $trackableSectionTypes = collect(config('partna.section_block_types', [
            'gallery',
            'services',
            'shop',
            'booking',
        ]))
            ->filter(fn ($type) => is_string($type) && trim($type) !== '')
            ->map(fn (string $type) => strtolower(trim($type)))
            ->values()
            ->all();
        $isTrackableLink = $blockGroup === 'links' && $blockType === 'link';
        $isTrackableSection =
            $blockGroup === 'sections'
            && in_array($blockType, $trackableSectionTypes, true);

        if (! $isTrackableLink && ! $isTrackableSection) {
            return $this->error('Block is not trackable for analytics', 422);
        }

        // 404 not 403: state check on a public endpoint — 403 reveals the block exists but is disabled
        if (! $block->is_active) {
            return $this->error('Block not found', 404);
        }

        // Sanitize referrer: discard values that are not valid URLs (e.g., injected strings)
        $rawReferrer = $data['referrer'] ?? $request->headers->get('referer');
        $referrer = ($rawReferrer !== null && filter_var($rawReferrer, FILTER_VALIDATE_URL)) ? $rawReferrer : null;

        // Dedup: return existing click if same visitor/session hit this block within 3 seconds.
        $hasIdentifier = ! empty($data['visitor_id']) || ! empty($data['session_id']);
        $click = null;
        if ($hasIdentifier) {
            $click = LinkClick::where('link_block_id', $block->id)
                ->where(function ($q) use ($data) {
                    if (! empty($data['visitor_id'])) {
                        $q->orWhere('visitor_id', $data['visitor_id']);
                    }
                    if (! empty($data['session_id'])) {
                        $q->orWhere('session_id', $data['session_id']);
                    }
                })
                ->where('occurred_at', '>=', now()->subSeconds(3))
                ->first();
        }

        if (! $click) {
            $click = new LinkClick([
                'occurred_at' => now(),
                'session_id' => $data['session_id'] ?? null,
                'visitor_id' => $data['visitor_id'] ?? null,
                'ip_hash' => $this->hashIp($request->ip()),
                'user_agent' => $request->userAgent(),
                'referrer' => $referrer,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
            ]);
            $click->professional_id = $site->professional_id;
            $click->site_id = $site->id;
            $click->link_block_id = $block->id;
            $click->save();
        }

        try {
            $this->analyticsCache->invalidateAnalytics($site->professional_id);
        } catch (Throwable) {
        }

        return $this->success([
            'message' => $isTrackableSection ? 'Section interaction recorded' : 'Click recorded',
            'click_id' => $click->id,
        ], 201);
    }

    public function cartEvent(CartEventRequest $request): JsonResponse
    {
        $data = $request->validated();

        $site = $this->resolveSiteFromData($data);

        if (! $site) {
            $statusCode = ! empty($data['site_id']) ? 422 : 404;

            return $this->error('Site not found', $statusCode);
        }

        // 404 not 403: public endpoint — returning 403 would reveal the site exists but is unpublished
        if (! $site->is_published) {
            return $this->error('Site not found', 404);
        }

        $event = new CartEvent([
            'event_type' => $data['event_type'],
            'occurred_at' => now(),
            'session_id' => $data['session_id'] ?? null,
            'visitor_id' => $data['visitor_id'] ?? null,
            'ip_hash' => $this->hashIp($request->ip()),
            'shopify_product_id' => $data['shopify_product_id'] ?? null,
            'quantity' => $data['quantity'] ?? null,
        ]);
        $event->professional_id = $site->professional_id;
        $event->site_id = $site->id;
        $event->save();

        try {
            $this->analyticsCache->invalidateAnalytics($site->professional_id);
        } catch (Throwable) {
        }

        return $this->success(['message' => 'Cart event recorded', 'event_id' => $event->id], 201);
    }
}
