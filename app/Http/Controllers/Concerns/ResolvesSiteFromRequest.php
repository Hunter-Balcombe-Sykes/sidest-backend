<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;

// V2: Resolves a Site by ID or subdomain (with subdomain alias fallback) from incoming request data.
trait ResolvesSiteFromRequest
{
    /**
     * Resolve the site by ID or subdomain (with alias fallback).
     */
    protected function resolveSiteFromData(array $data): ?Site
    {
        if (!empty($data['site_id'])) {
            return Site::query()->find($data['site_id']);
        }

        if (!empty($data['subdomain'])) {
            $subdomain = strtolower($data['subdomain']);

            // Try direct match
            $site = Site::query()->whereRaw('lower(subdomain) = ? ', [$subdomain])->first();
            if ($site) {
                return $site;
            }

            // Try alias
            $alias = SiteSubdomainAlias::query()->whereRaw('lower(subdomain) = ?', [$subdomain])->first();
            if ($alias) {
                return Site::query()->find($alias->site_id);
            }
        }

        return null;
    }
}
