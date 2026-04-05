<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

// V2: Builds public-facing QR code URLs from a slug, resolving scheme and host from config or request context.
trait BuildsQrCodeUrls
{
    /**
     * Build QR code URL from slug.
     */
    protected function qrUrl(string $qrSlug, Request $request): string
    {
        $publicDomain = (string) config('sidest.public_domain', '');
        $scheme = $this->baseScheme($request);

        $host = $publicDomain !== ''
            ? $publicDomain
            : (parse_url((string) config('app.url', ''), PHP_URL_HOST) ?: $request->getHost());

        return $scheme . '://' . $host . '/p/' . $qrSlug;
    }

    /**
     * Determine the scheme (http vs. https) from config or request.
     */
    protected function baseScheme(Request $request): string
    {
        $configured = (string) config('app.url', '');
        $scheme = parse_url($configured, PHP_URL_SCHEME);

        return is_string($scheme) && $scheme !== '' ? $scheme :  $request->getScheme();
    }
}
