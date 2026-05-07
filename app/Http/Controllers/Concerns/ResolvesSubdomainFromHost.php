<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

// V2: Extracts the subdomain from the request host by stripping the configured public domain suffix, with route parameter fallback.
trait ResolvesSubdomainFromHost
{
    /**
     * Resolve subdomain from X-Site-Subdomain header, then query/input (subdomain or slug keys), then host.
     */
    protected function resolveSiteSubdomain(Request $request): ?string
    {
        $fromHeader = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($fromHeader !== '') {
            return strtolower($fromHeader);
        }

        foreach (['subdomain', 'slug'] as $key) {
            $fromQuery = trim((string) $request->query($key, ''));
            if ($fromQuery !== '') {
                return strtolower($fromQuery);
            }
            $fromInput = trim((string) $request->input($key, ''));
            if ($fromInput !== '') {
                return strtolower($fromInput);
            }
        }

        $fromHost = $this->resolveSubdomainFromHost($request);

        return $fromHost ? strtolower($fromHost) : null;
    }

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
        $publicDomain = config('partna.public_domain');

        if (! $publicDomain || ! str_ends_with($host, $publicDomain)) {
            return null;
        }

        $suffix = '.'.ltrim($publicDomain, '.');
        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen($suffix));

        return $subdomain !== '' ? strtolower($subdomain) : null;
    }
}
