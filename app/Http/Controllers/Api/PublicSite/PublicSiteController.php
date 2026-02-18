<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\PublicSite\PublicSiteShowRequest;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Cache\SiteCacheService;

class PublicSiteController extends ApiController
{
    public function __construct(
        private SiteCacheService $siteCache
    ) {}

    public function show(PublicSiteShowRequest $request): Response
    {
        $subdomain = strtolower($request->validated()['subdomain']);

        $payload = $this->siteCache->getPublicSitePayload($subdomain);
        if ($payload) {
            return $this->success($payload);
        }

        $alias = SiteSubdomainAlias::query()
            ->whereRaw('lower(subdomain) = ?', [strtolower($subdomain)])
            ->first();

        if ($alias) {
            $site = Site::query()->find($alias->site_id);

            if ($site) {
                // Only redirect if the canonical site is actually published (exists in payload view)
                $canonicalPayload = $this->siteCache->getPublicSitePayload($site->subdomain);

                if ($canonicalPayload) {
                    $host = $site->subdomain . '.' . config('comet.public_domain');
                    $url = $request->getScheme() . '://' . $host . $request->getRequestUri();
                    return redirect()->to($url, 301);
                }
            }
        }

        return $this->error('Site not found.', 404);
    }

    // TOBIAS ADDED (NEEDS REVIEW)
    // Header-based fallback for public site lookup.
    // Reads subdomain from X-Site-Subdomain header instead of domain routing.
    // Used by the Next.js frontend proxy for path-based routing (e.g. /slug).
    public function showByHeader(Request $request)
    {
        $subdomain = $request->header('X-Site-Subdomain');
        if (!$subdomain || !is_string($subdomain)) {
            return $this->error('Missing X-Site-Subdomain header.', 400);
        }

        $subdomain = strtolower(trim($subdomain));

        $payload = $this->siteCache->getPublicSitePayload($subdomain);
        if ($payload) {
            return $this->success($payload);
        }

        $alias = \App\Models\Core\Site\SiteSubdomainAlias::query()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if ($alias) {
            $site = \App\Models\Core\Site\Site::query()->find($alias->site_id);
            if ($site) {
                $canonicalPayload = $this->siteCache->getPublicSitePayload($site->subdomain);
                if ($canonicalPayload) {
                    return $this->success($canonicalPayload);
                }
            }
        }

        return $this->error('Site not found.', 404);
    }
}
