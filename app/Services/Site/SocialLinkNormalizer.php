<?php

namespace App\Services\Site;

use InvalidArgumentException;

/**
 * Single source of truth for social link validation, normalization, and canonical
 * URL building. Reads the registry from config('sidest.social_platforms') and
 * applies platform-specific rules to user input.
 *
 * Used by:
 *   - ProfessionalLinkBlockController on link create/update (write path)
 *   - PublicConfigController for the GET /public/config/social-platforms endpoint
 *   - BackfillSocialLinksCommand for the one-shot tagging migration
 *
 * Security model:
 *   - Handle inputs are validated against bounded ASCII-only regexes (homoglyph + ReDoS protection).
 *   - URL inputs are parsed via parse_url() and host-checked against an allowlist.
 *   - Canonical URLs are always rebuilt from url_template, which is https-only.
 *   - getPublicRegistry() strips internal validation fields so they never leak to clients.
 *
 * See docs/social-links.md for the full conceptual model.
 */
class SocialLinkNormalizer
{
    /**
     * Return the public-safe view of the registry — display name, icon key,
     * and placeholder only. Strips handle_pattern, host_allowlist, and
     * url_path_extractor so internal validation logic never reaches the wire.
     *
     * @return array<int, array{key: string, display_name: string, icon_key: string, placeholder: string}>
     */
    public function getPublicRegistry(): array
    {
        $registry = config('sidest.social_platforms', []);
        $public = [];

        foreach ($registry as $key => $config) {
            $public[] = [
                'key' => $key,
                'display_name' => $config['display_name'],
                'icon_key' => $config['icon_key'],
                'placeholder' => $config['placeholder'],
            ];
        }

        return $public;
    }

    public function isKnownPlatform(string $key): bool
    {
        return array_key_exists($key, config('sidest.social_platforms', []));
    }

    /**
     * Validate and normalize a social link from either a handle or a URL.
     *
     * Algorithm:
     *   1. Handle path: strip leading '@', trim whitespace, validate against the
     *      platform's handle_pattern, build canonical URL via url_template.
     *   2. URL path: parse host via parse_url(), check against host_allowlist.
     *      Try to extract a handle via url_path_extractor — if it matches,
     *      recurse into the handle path (gives a clean canonical URL). If not
     *      (e.g. a deep link to a post), keep the URL as-is, no handle extracted.
     *   3. Neither provided: throw.
     *
     * @return array{url: string, handle: string|null, icon_key: string, display_name: string, platform_key: string}
     *
     * @throws InvalidArgumentException with a user-friendly message on validation failure.
     */
    public function normalize(string $platformKey, ?string $handle, ?string $url): array
    {
        $config = $this->resolvePlatform($platformKey);

        // Handle path takes precedence — produces the cleanest canonical URL.
        if ($handle !== null && $handle !== '') {
            return $this->normalizeHandle($platformKey, $config, $handle);
        }

        if ($url !== null && $url !== '') {
            return $this->normalizeUrl($platformKey, $config, $url);
        }

        throw new InvalidArgumentException("Provide either a handle or a URL for {$config['display_name']}.");
    }

    /**
     * Try to extract a clean handle from a URL belonging to this platform.
     * Returns null when the URL doesn't follow the simple `host/{handle}` shape
     * (e.g. deep links like instagram.com/p/abc123 — valid URL, no handle to lift).
     *
     * Used by the backfill command and the URL-path normalization flow.
     */
    public function extractHandleFromUrl(string $platformKey, string $url): ?string
    {
        $config = $this->resolvePlatform($platformKey);

        $parsed = parse_url($url);
        if (! is_array($parsed) || ! isset($parsed['host'], $parsed['path'])) {
            return null;
        }

        // parse_url() decodes punycode for us, so an IDN host like xn--... will
        // be returned as the punycode string itself. Our host_allowlist is plain
        // ASCII, so any punycode lookalike fails the allowlist check naturally.
        $host = strtolower($parsed['host']);
        if (! in_array($host, $config['host_allowlist'], true)) {
            return null;
        }

        if (preg_match($config['url_path_extractor'], $parsed['path'], $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array{url: string, handle: string, icon_key: string, display_name: string, platform_key: string}
     */
    private function normalizeHandle(string $platformKey, array $config, string $handle): array
    {
        $cleaned = ltrim(trim($handle), '@');

        if (preg_match($config['handle_pattern'], $cleaned) !== 1) {
            throw new InvalidArgumentException(
                "Invalid {$config['display_name']} handle. Expected format: {$config['placeholder']}."
            );
        }

        return [
            'url' => str_replace('{handle}', $cleaned, $config['url_template']),
            'handle' => $cleaned,
            'icon_key' => $config['icon_key'],
            'display_name' => $config['display_name'],
            'platform_key' => $platformKey,
        ];
    }

    /**
     * @return array{url: string, handle: string|null, icon_key: string, display_name: string, platform_key: string}
     */
    private function normalizeUrl(string $platformKey, array $config, string $url): array
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || ! isset($parsed['host'])) {
            throw new InvalidArgumentException(
                "That doesn't look like a valid URL for {$config['display_name']}."
            );
        }

        $host = strtolower($parsed['host']);
        if (! in_array($host, $config['host_allowlist'], true)) {
            throw new InvalidArgumentException(
                "That URL doesn't belong to {$config['display_name']}. Expected one of: ".implode(', ', $config['host_allowlist']).'.'
            );
        }

        // Try to extract a handle from the path. If we can, recurse into the
        // handle path so the stored URL is the clean canonical form (no query
        // params, no www, always https). If we can't (deep link, post URL),
        // keep the URL as-is — but we can't guarantee https/canonicalization
        // for arbitrary deep links, so we at least force the scheme to https.
        $path = $parsed['path'] ?? '/';
        if (preg_match($config['url_path_extractor'], $path, $matches) === 1) {
            return $this->normalizeHandle($platformKey, $config, $matches[1]);
        }

        // Lenient deep-link path: rebuild the URL with forced https + the original
        // path/query/fragment. No handle extracted.
        $rebuilt = 'https://'.$host.$path;
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $rebuilt .= '?'.$parsed['query'];
        }
        if (isset($parsed['fragment']) && $parsed['fragment'] !== '') {
            $rebuilt .= '#'.$parsed['fragment'];
        }

        return [
            'url' => $rebuilt,
            'handle' => null,
            'icon_key' => $config['icon_key'],
            'display_name' => $config['display_name'],
            'platform_key' => $platformKey,
        ];
    }

    /**
     * @return array{display_name: string, icon_key: string, placeholder: string, handle_pattern: string, url_template: string, host_allowlist: array<int, string>, url_path_extractor: string}
     */
    private function resolvePlatform(string $platformKey): array
    {
        $registry = config('sidest.social_platforms', []);

        if (! isset($registry[$platformKey])) {
            throw new InvalidArgumentException("Unknown social platform: {$platformKey}.");
        }

        return $registry[$platformKey];
    }
}
