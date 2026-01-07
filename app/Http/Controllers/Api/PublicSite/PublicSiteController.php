<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\PublicSite\PublicSiteShowRequest;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
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
}
