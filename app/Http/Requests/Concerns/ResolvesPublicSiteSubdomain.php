<?php

namespace App\Http\Requests\Concerns;

// V2: Public-site endpoints accept a subdomain via the route segment OR an
// X-Site-Subdomain header (analytics endpoints use the header path; the show
// endpoint is route-only). Encapsulates that resolution + lowercasing into one
// call from prepareForValidation().
trait ResolvesPublicSiteSubdomain
{
    /**
     * Merge a normalized `subdomain` key into the request payload, sourced from
     * the matched route parameter and optionally falling back to a header.
     *
     * Pass null (the default) for route-only resolution. Pass a header name to
     * fall back to that header when the route value is missing or empty.
     */
    protected function mergeSubdomainFromRoute(?string $headerName = null): void
    {
        $routeValue = $this->route('subdomain');
        $candidate = is_string($routeValue) ? $routeValue : '';

        if ($candidate === '' && $headerName !== null) {
            $headerValue = $this->header($headerName);
            $candidate = is_string($headerValue) ? trim($headerValue) : '';
        }

        if ($candidate !== '') {
            $this->merge(['subdomain' => strtolower($candidate)]);
        }
    }
}
