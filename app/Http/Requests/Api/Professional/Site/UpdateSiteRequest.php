<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Models\Core\Site\Site;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateSiteRequest extends BaseFormRequest
{

    protected function prepareForValidation(): void
    {
        if (is_string($this->subdomain ?? null)) {
            $this->merge([
                'subdomain' => strtolower(trim($this->subdomain)),
            ]);
        }

        // trim banner fields
        foreach (['banner_bucket','banner_path'] as $k) {
            if ($this->has($k) && is_string($this->input($k))) {
                $v = trim($this->input($k));
                $this->merge([$k => $v === '' ? null : $v]);
            }
        }
    }

    public function rules(): array
    {
        $professional = $this->attributes->get('professional');
        $currentSiteId = $professional?->site?->id;

        $bucket = (string) config('comet.media_bucket', 'media');
        $bannerPrefix = $currentSiteId ? "sites/{$currentSiteId}/banner." : 'sites/';

        return [
            // Settings: allowlist specific keys with validation
            'settings' => ['sometimes', 'array'],
            'settings.hero_title' => ['sometimes', 'string', 'max:100'],
            'settings.hero_subtitle' => ['sometimes', 'string', 'max:200'],
            'settings.primary_button_text' => ['sometimes', 'string', 'max:50'],
            'settings.primary_button_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.bio_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.accent_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.background_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.custom_css' => ['sometimes', 'nullable', 'string', 'max:10000'],

            // Subdomain: must be unique, not reserved, DNS-safe
            'subdomain' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:63',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                function ($attribute, $value, $fail) use ($currentSiteId) {
                    // Check reserved words
                    $reserved = array_map('strtolower', config('comet.reserved_subdomains', []));
                    if (in_array(strtolower($value), $reserved, true)) {
                        $fail('The subdomain "' . $value . '" is reserved and cannot be used.');
                        return;
                    }

                    // Check case-insensitive uniqueness
                    $exists = Site::whereRaw('lower(subdomain) = ?', [strtolower($value)])
                        ->when($currentSiteId, function ($query) use ($currentSiteId) {
                            $query->where('id', '!=', $currentSiteId);
                        })
                        ->exists();

                    if ($exists) {
                        $fail('This subdomain is already taken.');
                        return;
                    }

                    $aliasExists = DB::table('site_subdomain_aliases')
                        ->whereRaw('lower(subdomain) = ?', [strtolower($value)])
                        ->exists();

                    if ($aliasExists) {
                        $fail('This subdomain is already taken.');
                    }
                },
            ],

            // Theme (FIXED)
            'theme_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('themes', 'id'),
            ],

            // Publish
            'is_published' => ['sometimes', 'boolean'],

            // Banner (STRICT)
            'banner_bucket' => ['sometimes', 'nullable', 'string', 'max:255', Rule::in([$bucket])],
            'banner_path'   => [
                'sometimes','nullable','string','max:255',
                function ($attribute, $value, $fail) use ($bannerPrefix, $currentSiteId) {
                    if ($value === null) return;

                    if (!$currentSiteId) {
                        $fail('Site not found for banner upload.');
                        return;
                    }

                    if (!is_string($value) || !str_starts_with($value, $bannerPrefix)) {
                        $fail('Invalid banner_path: must match your prepared upload path.');
                        return;
                    }

                    if (!preg_match('/\.(jpg|png|webp)$/i', $value)) {
                        $fail('Invalid banner_path extension.');
                    }
                },
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {

            $this->enforcePair($validator, 'banner_bucket', 'banner_path');

            if ($this->input('is_published') === true) {
                $professional = $this->attributes->get('professional');
                $site = $professional?->site;

                if (!$site) {
                    $validator->errors()->add('is_published', 'Site not found.');
                    return;
                }

                if (empty($professional->display_name)) {
                    $validator->errors()->add('is_published', 'Cannot publish: professional must have a display name.');
                }

                $hasActiveBlock = $site->linkBlocks()->where('is_active', true)->exists();
                if (!$hasActiveBlock) {
                    $validator->errors()->add('is_published', 'Cannot publish: Site must have at least one active link block.');
                }

                $settings = is_array($site->settings) ? $site->settings : [];
                $incoming = $this->input('settings');

                if (is_array($incoming)) {
                    $settings = array_replace_recursive($settings, $incoming);
                }

                if (empty($settings['hero_title'])) {
                    $validator->errors()->add('is_published', 'Cannot publish: Site must have a hero title in settings.');
                }
            }
        });

    }

    private function enforcePair($validator, string $bucketKey, string $pathKey): void
    {
        $bucketProvided = $this->has($bucketKey);
        $pathProvided   = $this->has($pathKey);

        if ($bucketProvided xor $pathProvided) {
            $validator->errors()->add($bucketKey, "Provide both {$bucketKey} and {$pathKey} together.");
            $validator->errors()->add($pathKey, "Provide both {$bucketKey} and {$pathKey} together.");
            return;
        }

        if (!$bucketProvided && !$pathProvided) {
            return;
        }

        $bucketVal = $this->input($bucketKey);
        $pathVal   = $this->input($pathKey);

        if (($bucketVal === null) xor ($pathVal === null)) {
            $validator->errors()->add($bucketKey, "To clear, set BOTH {$bucketKey} and {$pathKey} to null.");
            $validator->errors()->add($pathKey, "To clear, set BOTH {$bucketKey} and {$pathKey} to null.");
        }
    }

    public function messages(): array
    {
        return [
            'subdomain.regex' => 'The subdomain must contain only lowercase letters, numbers, and hyphens, and cannot start or end with a hyphen.',
            'subdomain.unique' => 'This subdomain is already taken.',
            'subdomain.min' => 'The subdomain must be at least 3 characters.',
            'subdomain.max' => 'The subdomain cannot exceed 63 characters.',
            'settings.primary_color.regex' => 'The primary color must be a valid hex color (e.g., #000000 or #000).',
            'settings.accent_color.regex' => 'The accent color must be a valid hex color (e.g., #FFD700).',
            'settings.background_color.regex' => 'The background color must be a valid hex color.',
        ];
    }
}
