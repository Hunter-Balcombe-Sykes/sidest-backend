<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\Analytics\ClickRequest;
use App\Http\Requests\Api\Public\Analytics\PageviewRequest;
use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function pageview(PageviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve site by ID or subdomain
        $site = $this->resolveSite($data);

        if (!$site) {
            return response()->json(['message' => 'Site not found'], 404);
        }

        // Check if site is published
        if (!$site->is_published) {
            return response()->json(['message' => 'Site not published'], 403);
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

        return response()->json([
            'message' => 'Pageview recorded',
            'visit_id' => $visit->id,
        ], 201);
    }

    public function click(ClickRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve site by ID or subdomain
        $site = $this->resolveSite($data);

        if (!$site) {
            return response()->json(['message' => 'Site not found'], 404);
        }

        // Check if site is published
        if (!$site->is_published) {
            return response()->json(['message' => 'Site not published'], 403);
        }

        // Verify link block exists and belongs to this site
        $linkBlock = Block::where('id', $data['block_id'])
            ->where('site_id', $site->id)
            ->where('block_group', 'links')
            ->where('block_type', 'link')
            ->first();

        if (!$linkBlock) {
            return response()->json(['message' => 'Link block not found or does not belong to this site'], 404);
        }

        // Check if link block is active
        if (!$linkBlock->is_active) {
            return response()->json(['message' => 'Link block is not active'], 403);
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

        return response()->json([
            'message' => 'Click recorded',
            'click_id' => $click->id,
        ], 201);
    }

    /**
     * Resolve site from request data (by ID or subdomain)
     */
    private function resolveSite(array $data): ?Site
    {
        if (!empty($data['site_id'])) {
            return Site::find($data['site_id']);
        }

        if (!empty($data['subdomain'])) {
            $site = Site::whereRaw('lower(subdomain) = lower(?)', [$data['subdomain']])->first();
            if ($site) {
                return $site;
            }

            $alias = SiteSubdomainAlias::whereRaw('lower(subdomain) = lower(?)', [$data['subdomain']])->first();
            if ($alias) {
                return Site::find($alias->site_id);
            }
        }

        return null;
    }

    /**
     * Hash IP address for privacy compliance (GDPR)
     */
    private function hashIp(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }

        return hash('sha256', $ip . config('app.key'));
    }

    private function detectCountryCode(\Illuminate\Http\Request $request): ?string
    {
        // Pick the header your edge/CDN provides (Cloudflare / CloudFront / Vercel etc.)
        $code =
            $request->header('CF-IPCountry') // Cloudflare
            ?? $request->header('CloudFront-Viewer-Country') // AWS CloudFront
            ?? $request->header('X-Vercel-IP-Country'); // Vercel

        if (!is_string($code)) return null;

        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z]{2}$/', $code)) return null;

        return $code;
    }

    private function detectDeviceType(?string $ua): ?string
    {
        if (!$ua) return null;
        $u = strtolower($ua);

        // bots (optional)
        if (str_contains($u, 'bot') || str_contains($u, 'spider') || str_contains($u, 'crawler')) {
            return 'bot';
        }

        // tablet
        if (str_contains($u, 'ipad') || str_contains($u, 'tablet')) {
            return 'tablet';
        }

        // mobile
        if (
            str_contains($u, 'mobi') ||
            str_contains($u, 'iphone') ||
            str_contains($u, 'android')
        ) {
            return 'mobile';
        }

        return 'desktop';
    }
}
