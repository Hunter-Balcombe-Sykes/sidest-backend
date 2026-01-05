<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\DetectsClientInfo;
use App\Http\Controllers\Concerns\HashesClientData;
use App\Http\Controllers\Concerns\ResolvesSiteFromRequest;
use App\Http\Requests\Api\PublicSite\Analytics\ClickRequest;
use App\Http\Requests\Api\PublicSite\Analytics\PageviewRequest;
use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\Core\Site\Block;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends ApiController
{
    use DetectsClientInfo;
    use HashesClientData;
    use ResolvesSiteFromRequest;

    public function pageview(PageviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve site by ID or subdomain
        $site = $this->resolveSiteFromData($data);

        if (!$site) {
            return $this->error('Site not found', 404);
        }

        // Check if site is published
        if (!$site->is_published) {
            return $this->error('Site not published', 403);
        }


        // Create pageview record
        $visit = new SiteVisit([
            'occurred_at'  => now(),
            'session_id'   => $data['session_id'] ?? null,
            'visitor_id'   => $data['visitor_id'] ?? null,
            'ip_hash'      => $this->hashIp($request->ip()),
            'user_agent'   => $request->userAgent(),
            'referrer'     => $data['referrer'] ?? $request->headers->get('referer'),
            'utm_source'   => $data['utm_source'] ?? null,
            'utm_medium'   => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'country_code' => $this->detectCountryCode($request),
            'device_type'  => $this->detectDeviceType($request->userAgent()),
        ]);
        $visit->professional_id = $site->professional_id;
        $visit->site_id = $site->id;
        $visit->save();

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

        if (!$site) {
            return $this->error('Site not found', 404);
        }

        // Check if site is published
        if (!$site->is_published) {
            return $this->error('Site not published', 403);
        }

        // Verify link block exists and belongs to this site
        $linkBlock = Block::where('id', $data['block_id'])
            ->where('site_id', $site->id)
            ->where('block_group', 'links')
            ->where('block_type', 'link')
            ->first();

        if (!$linkBlock) {
            return $this->error('Link block not found or does not belong to this site', 404);
        }

        // Check if link block is active
        if (!$linkBlock->is_active) {
            return $this->error('Link block is not active', 403);
        }

        // Create click record
        $click = new LinkClick([
            'occurred_at'  => now(),
            'session_id'   => $data['session_id'] ?? null,
            'visitor_id'   => $data['visitor_id'] ?? null,
            'ip_hash'      => $this->hashIp($request->ip()),
            'user_agent'   => $request->userAgent(),
            'referrer'     => $data['referrer'] ?? $request->headers->get('referer'),
            'utm_source'   => $data['utm_source'] ?? null,
            'utm_medium'   => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
        ]);
        $click->professional_id = $site->professional_id;
        $click->site_id = $site->id;
        $click->block_id = $linkBlock->id;
        $click->save();

        return $this->success([
            'message' => 'Click recorded',
            'click_id' => $click->id,
        ], 201);
    }
}
