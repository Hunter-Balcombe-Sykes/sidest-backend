<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

// V2: Extracts the subdomain from the request host by stripping the configured public domain suffix, with route parameter fallback.
trait ResolvesSubdomainFromHost
{
    /**
     * Extract subdomain from requests host based on public domain config.
     */
    protected function resolveSubdomainFromHost(Request $request): ?string
    {
        // Try route parameter first
        $routeSubdomain = $request->route('subdomain');
        if (is_string($routeSubdomain) && $routeSubdomain !== '') {
            return strtolower($routeSubdomain);
        }

        // Extract from host
        $host = $request->getHost();
        $publicDomain = config('sidest.public_domain');

        if (! $publicDomain || !str_ends_with($host, $publicDomain)) {
            return null;
        }

        $suffix = '.' . ltrim($publicDomain, '.');
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));

        return $subdomain !== '' ? strtolower($subdomain) : null;
    }
}
