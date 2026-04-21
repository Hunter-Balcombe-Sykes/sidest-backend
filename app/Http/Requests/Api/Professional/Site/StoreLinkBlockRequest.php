<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates new link block creation. Supports two write modes:
 *
 *   1. **Social mode** — `platform` is set (must be a key in
 *      config('sidest.social_platforms')). Either `handle` OR `url` must be
 *      provided. The controller delegates to SocialLinkNormalizer to validate,
 *      strip a leading '@', and rebuild a canonical https URL.
 *
 *   2. **Custom mode** — no `platform`. Behaves like the legacy contract:
 *      `title` AND `url` are both required. `icon_key` is optional.
 *
 * Security:
 *   - Custom-mode URLs are restricted to http/https schemes only — no
 *     javascript:, data:, file:, ftp:. Caught in withValidator() below.
 *   - `title` is sanitized in prepareForValidation(): control chars stripped,
 *     HTML tags removed via strip_tags() as defense-in-depth on top of frontend
 *     escaping.
 *   - Social-mode handle/url validation is delegated to the normalizer service
 *     which enforces ASCII-only handles (homoglyph protection) and host
 *     allowlists (phishing protection).
 *
 * See docs/social-links.md for the full contract.
 */
class StoreLinkBlockRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        $url = $this->input('url');
        $iconKey = $this->input('icon_key');
        $platform = $this->input('platform');
        $handle = $this->input('handle');

        $this->merge([
            // Title sanitization: strip control chars + HTML tags. Defense-in-depth
            // on top of frontend escaping. Without this, a user could store
            // `<script>alert(1)</script>` and rely on a buggy renderer to execute it.
            'title' => is_string($title)
                ? preg_replace('/[\x00-\x1F\x7F]/', '', strip_tags(trim($title)))
                : $title,
            'url' => is_string($url) ? trim($url) : $url,
            'icon_key' => is_string($iconKey) ? trim($iconKey) : $iconKey,
            'platform' => is_string($platform) ? trim($platform) : $platform,
            'handle' => is_string($handle) ? trim($handle) : $handle,
        ]);

        if (is_array($this->settings ?? null)) {
            $this->merge([
                'settings' => array_merge($this->settings, [
                    'note' => is_string($this->settings['note'] ?? null)
                        ? trim($this->settings['note'])
                        : ($this->settings['note'] ?? null),
                ]),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // Social mode fields. `url` is validated lazily as a string here
            // because the normalizer runs its own host-allowlist check; using
            // Laravel's `url` rule would reject deep links we want to accept.
            'platform' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('sidest.social_platforms', [])))],
            'handle' => ['sometimes', 'nullable', 'string', 'max:100'],

            // Custom mode fields (also reused for social mode auto-fallbacks)
            'title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'icon_key' => ['sometimes', 'nullable', 'string', Rule::in(config('sidest.link_block_icon_keys', []))],

            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.highlight' => ['sometimes', 'boolean'],
            'settings.note' => ['sometimes', 'string', 'max:140'],

            // Category enum — always validated against the registry when supplied.
            // Required for custom links (enforced in withValidator); optional for
            // social links (controller falls back to the platform's default_category).
            'category' => ['sometimes', 'nullable', 'string', Rule::in(config('sidest.link_categories', []))],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $platform = $this->input('platform');
            $handle = $this->input('handle');
            $url = $this->input('url');
            $title = $this->input('title');

            // Social vs custom mode discriminator
            if ($platform !== null && $platform !== '') {
                // Social mode: must have handle OR url
                if (($handle === null || $handle === '') && ($url === null || $url === '')) {
                    $validator->errors()->add('handle', 'Provide either a handle or a URL for this platform.');
                }
            } else {
                // Custom mode: title AND url both required (legacy contract preserved)
                if ($title === null || $title === '') {
                    $validator->errors()->add('title', 'The title field is required for custom links.');
                }
                if ($url === null || $url === '') {
                    $validator->errors()->add('url', 'The url field is required for custom links.');
                } elseif (! $this->isAllowedScheme($url)) {
                    $validator->errors()->add('url', 'Custom link URLs must use http or https.');
                }

                // Category is required for custom links; platform links fall back
                // to the registry's default_category when omitted.
                $category = $this->input('category');
                if ($category === null || $category === '') {
                    $validator->errors()->add('category', 'The category field is required for custom links.');
                }
            }

            // Settings allowlist (existing behaviour)
            $settings = $this->input('settings');
            if (is_array($settings)) {
                $allowed = config('sidest.link_block_settings_keys', []);
                $extra = array_diff(array_keys($settings), $allowed);
                if (! empty($extra)) {
                    $validator->errors()->add(
                        'settings',
                        'The settings field contains unsupported keys: '.implode(', ', $extra)
                    );
                }
            }
        });
    }

    /**
     * Reject schemes other than http/https for custom links. Blocks
     * javascript:, data:, file:, ftp:, and similar XSS / exfiltration vectors.
     */
    private function isAllowedScheme(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
