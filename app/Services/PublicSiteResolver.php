<?php

namespace App\Services;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;

class PublicSiteResolver
{
    public function resolvePublishedSite(string $subdomain): ?Site
    {
        $subdomain = strtolower($subdomain);

        $site = Site::query()
            ->published()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if ($site) return $site;

        $alias = SiteSubdomainAlias::query()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if (!$alias) return null;

        return Site::query()->published()->find($alias->site_id);
    }
}
