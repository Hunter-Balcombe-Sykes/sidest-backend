<?php

namespace App\Services;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;

class PublicSiteResolver
{
    public function resolvePublishedSite(string $subdomain): ?Site
    {
        $subdomain = strtolower($subdomain);

        $siteQuery = Site::query()
            ->where('is_published', true)
            ->whereHas('professional', function ($q) {
                $q->where('status', 'active');
            });

        $site = (clone $siteQuery)::query()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if ($site) return $site;

        $alias = SiteSubdomainAlias::query()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if (!$alias) return null;

        return (clone $siteQuery)
            ->where('id', $alias->site_id)
            ->first();
    }
}
