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
    }

    public function rules(): array
    {
        $professional = $this->attributes->get('professional');
        $currentSiteId = $professional?->site?->id;

        return [
            // Settings: allowlist specific keys with validation
            'settings' => ['sometimes', 'array'],
            'settings.hero_title' => ['sometimes', 'string', 'max:100'],
            'settings.hero_subtitle' => ['sometimes', 'string', 'max:200'],
            'settings.primary_button_text' => ['sometimes', 'string', 'max:50'],
            'settings.primary_button_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.bio_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.secondary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            'settings.booking_mode' => ['sometimes', 'string', Rule::in(['manual', 'smart'])],
            'settings.manual_booking_url' => ['sometimes', 'nullable', 'url', 'max:2048'],

            // ------ TOBIAS ADDITIONS TO REVIEW --------
            // Store: featured Shopify product GIDs (max 10)
            'settings.selected_products'   => ['sometimes', 'array', 'max:10'],
            'settings.selected_products.*' => ['string', 'max:255'],
            // ------ END TOBIAS ADDITIONS --------

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
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
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
