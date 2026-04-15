<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Models\Core\Site\Site;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// V2: Validates site updates — settings (design, colors, typography, media), subdomain uniqueness, theme, and publish readiness checks.
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
            'settings.design' => ['sometimes', 'array'],
            'settings.design.border_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.accent_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.white_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.dark_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.border_radius' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.border_width' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.general_spacing_padding' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.background_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.text_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.button_background' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.button_text_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.secondary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.theme_variant' => ['sometimes', 'nullable', 'string', 'max:50'],
            'settings.design.product_image_ratio' => ['sometimes', 'nullable', 'string', 'in:1/1,4/5'],
            'settings.design.custom_photo_position' => ['sometimes', 'nullable', 'string', 'in:before,after,mixed'],
            'settings.design.typography' => ['sometimes', 'array'],
            // heading_font / body_font were free-string legacy fields, replaced
            // by the single `font_family` picker further down. Explicitly
            // prohibited so old clients can't repopulate the removed keys.
            'settings.design.typography.heading_font' => ['prohibited'],
            'settings.design.typography.body_font' => ['prohibited'],
            'settings.design.typography.font_file_name' => ['prohibited'],
            'settings.design.typography.font_file_path' => ['prohibited'],
            'settings.design.typography.font_file_url' => ['prohibited'],
            'settings.design.typography.logo_letter_spacing' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.typography.logo_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.media' => ['sometimes', 'array'],
            'settings.design.media.brand_logo_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.design.media.brand_logo_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'settings.design.media.brand_logo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.design.media.placeholder_sitepage_images' => ['sometimes', 'array', 'max:5'],
            'settings.design.media.placeholder_sitepage_images.*.name' => ['required_with:settings.design.media.placeholder_sitepage_images', 'string', 'max:255'],
            'settings.design.media.placeholder_sitepage_images.*.path' => ['required_with:settings.design.media.placeholder_sitepage_images', 'string', 'max:2048'],
            'settings.design.media.placeholder_sitepage_images.*.url' => ['required_with:settings.design.media.placeholder_sitepage_images', 'url', 'max:2048'],

            // --- New unified brand-design shape ---
            // Populated by the Shopify sync job (SyncShopifyBrandDesignJob) and, later,
            // by the restructured /account/design UI. Nullable so the sync job can
            // leave unset values alone when Shopify returns no value for a field.
            // The legacy design.* keys above stay in place until the UI is rebuilt
            // against this shape — drop them in a follow-up migration after that.
            'settings.design.colors' => ['sometimes', 'array'],
            'settings.design.colors.background' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.colors.text' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.colors.accent' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.colors.border' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            // 3-bucket enums normalise noisy theme pixel values into coherent design
            // buckets that each Sidest theme design can map to its own concrete values.
            // Enum values mirror the dashboard dropdown labels (lowercased): the
            // middle value is `default` for every bucket so the storage layer
            // and the UI speak the same vocabulary.
            'settings.design.corner_radius' => ['sometimes', 'nullable', 'string', Rule::in(['square', 'default', 'pill'])],
            'settings.design.border_thickness' => ['sometimes', 'nullable', 'string', Rule::in(['hairline', 'default', 'bold'])],
            'settings.design.section_spacing' => ['sometimes', 'nullable', 'string', Rule::in(['tight', 'default', 'spacious'])],
            // Logos are downloaded from Shopify into our own storage so the URLs
            // are stable even if Shopify CDN tokens rotate.
            'settings.design.logo' => ['sometimes', 'array'],
            'settings.design.logo.full_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.design.logo.square_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.design.slogan' => ['sometimes', 'nullable', 'string', 'max:200'],
            // Font picker. Not Shopify-synced — purely a Sidest-side choice
            // from a fixed shortlist. Keep this in sync with lib/design/fonts.ts
            // on the frontend. Default for new brands: helvetica_neue.
            'settings.design.font_family' => ['sometimes', 'nullable', 'string', Rule::in([
                'neue_haas_grotesk',
                'helvetica_neue',
                'forma_djr',
                'nb_architekt',
                'swiss_721',
            ])],
            'settings.brand_partner' => ['prohibited'],
            'settings.brandPartner' => ['prohibited'],
            'settings.additional_brand_partners' => ['prohibited'],
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.charlie_enabled' => ['sometimes', 'boolean'],
            'settings.charlieEnabled' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            'settings.booking_mode' => ['sometimes', 'string', Rule::in(['manual', 'smart'])],
            'settings.manual_booking_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.selected_products' => ['prohibited'],

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
                    $reserved = array_map('strtolower', config('sidest.reserved_subdomains', []));
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

                    $aliasExists = DB::table('site.site_subdomain_aliases')
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
            'settings.design.border_color.regex' => 'The design border color must be a valid hex color.',
            'settings.design.accent_color.regex' => 'The design accent color must be a valid hex color.',
            'settings.design.white_color.regex' => 'The design white color must be a valid hex color.',
            'settings.design.dark_color.regex' => 'The design dark color must be a valid hex color.',
            'settings.design.background_color.regex' => 'The background color must be a valid hex color.',
            'settings.design.text_color.regex' => 'The text color must be a valid hex color.',
            'settings.design.button_background.regex' => 'The button background color must be a valid hex color.',
            'settings.design.button_text_color.regex' => 'The button text color must be a valid hex color.',
            'settings.design.primary_color.regex' => 'The primary color must be a valid hex color.',
            'settings.design.secondary_color.regex' => 'The secondary color must be a valid hex color.',
            'settings.brand_partner.prohibited' => 'Use brand partner endpoints to manage brand relationships.',
            'settings.brandPartner.prohibited' => 'Use brand partner endpoints to manage brand relationships.',
            'settings.additional_brand_partners.prohibited' => 'Use brand partner endpoints to manage brand relationships.',
            'settings.selected_products.prohibited' => 'Use /api/store/featured-products for product selections.',
            'settings.design.typography.heading_font.prohibited' => 'Use settings.design.font_family for the unified font picker.',
            'settings.design.typography.body_font.prohibited' => 'Use settings.design.font_family for the unified font picker.',
            'settings.design.typography.font_file_name.prohibited' => 'Custom font uploads are no longer supported — use settings.design.font_family.',
            'settings.design.typography.font_file_path.prohibited' => 'Custom font uploads are no longer supported — use settings.design.font_family.',
            'settings.design.typography.font_file_url.prohibited' => 'Custom font uploads are no longer supported — use settings.design.font_family.',
            // New unified shape messages.
            'settings.design.colors.background.regex' => 'The background color must be a valid hex color (e.g., #FFFFFF).',
            'settings.design.colors.text.regex' => 'The text color must be a valid hex color.',
            'settings.design.colors.accent.regex' => 'The accent color must be a valid hex color.',
            'settings.design.colors.border.regex' => 'The border color must be a valid hex color.',
            'settings.design.corner_radius.in' => 'Corner radius must be one of: square, default, pill.',
            'settings.design.border_thickness.in' => 'Border thickness must be one of: hairline, default, bold.',
            'settings.design.section_spacing.in' => 'Section spacing must be one of: tight, default, spacious.',
            'settings.design.font_family.in' => 'Font must be one of: neue_haas_grotesk, helvetica_neue, forma_djr, nb_architekt, swiss_721.',
        ];
    }
}
