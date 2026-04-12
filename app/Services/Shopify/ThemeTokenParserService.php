<?php

namespace App\Services\Shopify;

// V2: Extracts normalized design tokens from a Shopify storefront HTML. Maps known theme CSS variable patterns
// (Dawn, Debut, Prestige, Symmetry) to Side St's canonical token set. Pure-logic service, no side effects.
class ThemeTokenParserService
{
    public const TOKEN_KEYS = [
        'primary_color',
        'secondary_color',
        'background_color',
        'text_color',
        'border_radius',
        'border_width',
        'button_background',
        'button_text_color',
        'heading_font',
        'body_font',
    ];

    // Variable-name -> canonical token. Lowercased keys for case-insensitive matching.
    // Ordered so that more specific mappings can win when multiple patterns resolve to the same canonical token.
    private const VARIABLE_MAP = [
        // Primary / accent
        '--color-base-accent-1' => 'primary_color',          // Dawn
        '--color-accent' => 'primary_color',                 // Prestige
        '--color-primary' => 'primary_color',                // Symmetry / generic
        '--brand-color' => 'primary_color',                  // Debut
        '--accent-color' => 'primary_color',

        // Secondary
        '--color-base-accent-2' => 'secondary_color',        // Dawn
        '--color-secondary' => 'secondary_color',            // Symmetry / generic
        '--secondary-color' => 'secondary_color',

        // Background
        '--color-base-background-1' => 'background_color',   // Dawn
        '--color-body-bg' => 'background_color',             // Symmetry
        '--color-background' => 'background_color',
        '--background' => 'background_color',                // Prestige
        '--bg-color' => 'background_color',
        '--color-bg' => 'background_color',

        // Text
        '--color-base-text' => 'text_color',                 // Dawn
        '--color-body-text' => 'text_color',                 // Symmetry
        '--color-text' => 'text_color',
        '--text-color' => 'text_color',                      // Prestige / generic

        // Border radius
        '--buttons-radius' => 'border_radius',               // Dawn (button radius used as primary)
        '--border-radius' => 'border_radius',
        '--rounded' => 'border_radius',
        '--radius' => 'border_radius',

        // Border width
        '--buttons-border-width' => 'border_width',          // Dawn
        '--border-width' => 'border_width',

        // Button background
        '--color-button' => 'button_background',             // Dawn
        '--button-background' => 'button_background',        // Prestige / generic
        '--primary-button-background' => 'button_background',
        '--color-btn-primary' => 'button_background',        // Debut

        // Button text
        '--color-button-text' => 'button_text_color',        // Dawn
        '--button-text-color' => 'button_text_color',
        '--primary-button-text-color' => 'button_text_color',
        '--color-btn-primary-text' => 'button_text_color',   // Debut
    ];

    // Font variable hints — used to detect heading vs body font from CSS
    private const FONT_HEADING_HINTS = [
        '--font-heading-family',
        '--heading-font-family',
        '--heading-font',
        '--font-header-family',
    ];

    private const FONT_BODY_HINTS = [
        '--font-body-family',
        '--body-font-family',
        '--body-font',
        '--font-base-family',
    ];

    /**
     * Main entry. Extracts design tokens from raw storefront HTML.
     *
     * @return array<string, mixed>
     */
    public function extractTokens(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $tokens = [];

        $variables = $this->extractCssCustomProperties($html);

        foreach ($variables as $name => $value) {
            $canonical = self::VARIABLE_MAP[$name] ?? null;
            if ($canonical === null) {
                continue;
            }

            if (isset($tokens[$canonical])) {
                // Already set by a higher-priority variable, skip.
                continue;
            }

            $normalized = $this->normalizeValue($canonical, $value);
            if ($normalized !== null) {
                $tokens[$canonical] = $normalized;
            }
        }

        $fonts = $this->extractFonts($html, $variables);

        if (isset($fonts['heading_font'])) {
            $tokens['heading_font'] = $fonts['heading_font'];
        }

        if (isset($fonts['body_font'])) {
            $tokens['body_font'] = $fonts['body_font'];
        }

        return $tokens;
    }

    /**
     * Extract CSS custom property declarations from <style> blocks and inline style attributes.
     * Returns a lowercased variable-name -> raw value map.
     *
     * @return array<string, string>
     */
    private function extractCssCustomProperties(string $html): array
    {
        $variables = [];

        // Pull out every <style>...</style> block
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $styleMatches) === false) {
            return [];
        }

        $blocks = $styleMatches[1] ?? [];

        // Also consider root-level custom properties set via inline style="" on <html> or <body>
        if (preg_match('/<(?:html|body)[^>]*style\s*=\s*"([^"]*)"/i', $html, $inlineMatch)) {
            $blocks[] = $inlineMatch[1];
        }

        foreach ($blocks as $block) {
            if (! is_string($block) || $block === '') {
                continue;
            }

            // Match "--var-name: value;" declarations. The value stops at ; or } or newline.
            // Use a conservative pattern to avoid capturing across declarations.
            if (preg_match_all('/--([a-z0-9_-]+)\s*:\s*([^;}\r\n]+)/i', $block, $declMatches) === false) {
                continue;
            }

            $names = $declMatches[1] ?? [];
            $values = $declMatches[2] ?? [];

            foreach ($names as $i => $name) {
                $key = '--'.strtolower((string) $name);
                $raw = trim((string) ($values[$i] ?? ''));

                if ($raw === '') {
                    continue;
                }

                // First occurrence wins so :root/body declarations aren't overwritten by component overrides later in the doc.
                if (! isset($variables[$key])) {
                    $variables[$key] = $raw;
                }
            }
        }

        return $variables;
    }

    /**
     * Normalize a raw CSS value into a canonical form based on the token type.
     * Colors become hex strings. Spacing values pass through as trimmed strings.
     */
    private function normalizeValue(string $token, string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === 'initial' || $raw === 'inherit' || $raw === 'unset' || str_starts_with($raw, 'var(')) {
            return null;
        }

        if (str_ends_with($token, '_color') || str_starts_with($token, 'button')) {
            return $this->normalizeColor($raw);
        }

        if ($token === 'border_radius' || $token === 'border_width') {
            // Keep CSS-valid length strings. Append "px" if value is a bare number (Shopify sometimes stores just "8").
            $cleaned = preg_replace('/\s+/', '', $raw) ?? $raw;

            if (preg_match('/^-?\d+(\.\d+)?$/', $cleaned)) {
                return $cleaned.'px';
            }

            if (preg_match('/^-?\d+(\.\d+)?(px|rem|em|%)$/i', $cleaned)) {
                return strtolower($cleaned);
            }

            return null;
        }

        return $raw;
    }

    /**
     * Normalize a CSS color into a #rrggbb hex string.
     * Supports #rgb, #rrggbb, #rrggbbaa, rgb(), rgba(), Shopify RGB triplets like "26, 26, 26".
     */
    private function normalizeColor(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // Already hex
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $raw)) {
            return $this->expandHex($raw);
        }

        // rgb()/rgba()
        if (preg_match('/^rgba?\s*\(([^)]+)\)$/i', $raw, $m)) {
            $parts = array_map('trim', explode(',', $m[1]));
            if (count($parts) >= 3) {
                return $this->rgbTripletToHex($parts[0], $parts[1], $parts[2]);
            }
        }

        // Shopify-style bare RGB triplet: "26, 26, 26"
        if (preg_match('/^(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})$/', $raw, $m)) {
            return $this->rgbTripletToHex($m[1], $m[2], $m[3]);
        }

        return null;
    }

    private function expandHex(string $hex): ?string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        } elseif (strlen($hex) === 4) {
            // Strip alpha from #rgba shorthand
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        } elseif (strlen($hex) === 8) {
            // Strip alpha from #rrggbbaa
            $hex = substr($hex, 0, 6);
        } elseif (strlen($hex) !== 6) {
            return null;
        }

        return '#'.strtolower($hex);
    }

    private function rgbTripletToHex(string $r, string $g, string $b): ?string
    {
        $r = (int) $r;
        $g = (int) $g;
        $b = (int) $b;

        if ($r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255) {
            return null;
        }

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Extract heading_font and body_font from the HTML.
     * Each returns ['family' => string, 'source_url' => ?string, 'source_type' => 'google'|'self-hosted'|null]
     *
     * @param  array<string, string>  $cssVariables  Pre-extracted CSS custom properties
     * @return array{heading_font?: array, body_font?: array}
     */
    public function extractFonts(string $html, array $cssVariables = []): array
    {
        $result = [];

        // 1. Look for heading/body font family CSS variables
        $headingFamily = $this->findFontFamily($cssVariables, self::FONT_HEADING_HINTS);
        $bodyFamily = $this->findFontFamily($cssVariables, self::FONT_BODY_HINTS);

        // 2. Parse Google Fonts <link> tags for source URLs
        $googleFonts = $this->extractGoogleFontLinks($html);

        // 3. Parse @font-face declarations
        $selfHostedFonts = $this->extractFontFaceDeclarations($html);

        if ($headingFamily !== null) {
            $result['heading_font'] = $this->buildFontRecord($headingFamily, $googleFonts, $selfHostedFonts);
        }

        if ($bodyFamily !== null) {
            $result['body_font'] = $this->buildFontRecord($bodyFamily, $googleFonts, $selfHostedFonts);
        }

        // If no CSS variables found but we did find Google Fonts, use the first one as a best-effort guess.
        if (empty($result) && ! empty($googleFonts)) {
            $first = $googleFonts[0];
            $result['body_font'] = [
                'family' => $first['family'],
                'source_url' => $first['url'],
                'source_type' => 'google',
            ];
        }

        return $result;
    }

    private function findFontFamily(array $variables, array $hints): ?string
    {
        foreach ($hints as $hint) {
            $value = $variables[strtolower($hint)] ?? null;
            if (is_string($value) && $value !== '') {
                return $this->extractFirstFontFamily($value);
            }
        }

        return null;
    }

    /**
     * From a CSS font-family declaration like `"Inter", sans-serif`, return the first family name.
     */
    private function extractFirstFontFamily(string $declaration): ?string
    {
        $declaration = trim($declaration);
        $first = explode(',', $declaration)[0] ?? '';
        $first = trim($first, " \t\n\r\0\x0B\"'");

        if ($first === '' || strlen($first) > 100) {
            return null;
        }

        // Allow letters, numbers, spaces, hyphens, dots. Reject anything that looks like code injection.
        if (! preg_match('/^[a-zA-Z0-9\s\-\.]+$/', $first)) {
            return null;
        }

        return $first;
    }

    /**
     * Build a font record for a given family, attaching a source URL if we can find one.
     *
     * @param  array<int, array{family: string, url: string}>  $googleFonts
     * @param  array<int, array{family: string, url: string}>  $selfHostedFonts
     * @return array{family: string, source_url: ?string, source_type: ?string}
     */
    private function buildFontRecord(string $family, array $googleFonts, array $selfHostedFonts): array
    {
        foreach ($googleFonts as $font) {
            if (strcasecmp($font['family'], $family) === 0) {
                return ['family' => $family, 'source_url' => $font['url'], 'source_type' => 'google'];
            }
        }

        foreach ($selfHostedFonts as $font) {
            if (strcasecmp($font['family'], $family) === 0) {
                return ['family' => $family, 'source_url' => $font['url'], 'source_type' => 'self-hosted'];
            }
        }

        return ['family' => $family, 'source_url' => null, 'source_type' => null];
    }

    /**
     * Parse <link> tags pointing at Google Fonts.
     *
     * @return array<int, array{family: string, url: string}>
     */
    private function extractGoogleFontLinks(string $html): array
    {
        $fonts = [];

        if (preg_match_all('/<link[^>]+href=["\']([^"\']*fonts\.googleapis\.com[^"\']*)["\'][^>]*>/i', $html, $matches) === false) {
            return [];
        }

        foreach ($matches[1] ?? [] as $url) {
            // family=Inter:wght@400;700  or  family=Playfair+Display&display=swap
            if (preg_match_all('/family=([^&]+)/', $url, $familyMatches)) {
                foreach ($familyMatches[1] ?? [] as $familyParam) {
                    // Strip variant specs after colon
                    $familyName = explode(':', $familyParam)[0];
                    $familyName = str_replace('+', ' ', urldecode($familyName));
                    $familyName = trim($familyName);

                    if ($familyName !== '' && strlen($familyName) <= 100) {
                        $fonts[] = ['family' => $familyName, 'url' => $url];
                    }
                }
            }
        }

        return $fonts;
    }

    /**
     * Parse @font-face declarations for self-hosted fonts.
     *
     * @return array<int, array{family: string, url: string}>
     */
    private function extractFontFaceDeclarations(string $html): array
    {
        $fonts = [];

        if (preg_match_all('/@font-face\s*\{([^}]+)\}/is', $html, $matches) === false) {
            return [];
        }

        foreach ($matches[1] ?? [] as $block) {
            $family = null;
            $url = null;

            if (preg_match('/font-family\s*:\s*["\']?([^;"\'\n]+)["\']?\s*;?/i', $block, $fm)) {
                $family = trim($fm[1], " \t\n\r\0\x0B\"'");
            }

            if (preg_match('/url\s*\(\s*["\']?([^)"\']+)["\']?\s*\)/i', $block, $um)) {
                $url = trim($um[1]);
            }

            if (is_string($family) && $family !== '' && is_string($url) && $url !== '' && strlen($family) <= 100) {
                $fonts[] = ['family' => $family, 'url' => $url];
            }
        }

        return $fonts;
    }
}
