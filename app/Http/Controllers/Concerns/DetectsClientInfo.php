<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

// V2: Detects client country code from CDN headers (Cloudflare, CloudFront, Vercel) and device type from user agent strings.
trait DetectsClientInfo
{
    /**
     * Detect country code from CDN headers (Cloudflare, CloudFront, Vercel).
     */
    protected function detectCountryCode(Request $request): ?string
    {
        $code =
            $request->header('CF-IPCountry') // Cloudflare
            ?? $request->header('CloudFront-Viewer-Country') // AWS CloudFront
            ?? $request->header('X-Vercel-IP-Country'); // Vercel

        if (! is_string($code)) {
            return null;
        }

        $code = strtoupper(trim($code));

        if (! preg_match('/^[A-Z]{2}$/', $code)) {
            return null;
        }

        return $code;
    }

    /**
     * Detect devices type from user agent.
     */
    protected function detectDeviceType(?string $ua): ?string
    {
        if (! $ua) {
            return null;
        }

        $u = strtolower($ua);

        // Bots
        if (str_contains($u, 'bot') || str_contains($u, 'spider') || str_contains($u, 'crawler')) {
            return 'bot';
        }

        // Tablet
        if (str_contains($u, 'ipad') || str_contains($u, 'tablet')) {
            return 'tablet';
        }

        // Mobile
        if (str_contains($u, 'mobi') || str_contains($u, 'iphone') || str_contains($u, 'android')) {
            return 'mobile';
        }

        return 'desktop';
    }
}
