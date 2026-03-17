<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite;

use App\Models\Core\Site\Site;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StaffUpdateSiteRequest extends BaseFormRequest
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
        $professional = $this->route('professional');
        $currentSiteId = $professional?->site?->id;

        return [
            // Staff PATCH semantics: "sometimes" means optional, but if present validate it.
            'theme_id' => [
                'sometimes',
                'nullable',
                'uuid',
                // IMPORTANT: using Rule::exists avoids the "connection.table" parsing issue.
                Rule::exists('themes', 'id'),
            ],

            'settings' => ['sometimes', 'array'],

            // Subdomain: staff can update with same constraints as pros
            'subdomain' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:63',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                function ($attribute, $value, $fail) use ($currentSiteId) {
                    $reserved = array_map('strtolower', config('comet.reserved_subdomains', []));
                    if (in_array(strtolower($value), $reserved, true)) {
                        $fail('The subdomain "' . $value . '" is reserved and cannot be used.');
                        return;
                    }

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

            'is_published' => ['sometimes', 'boolean'],

            // Settings: allowlist specific keys with validation
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
            'settings.design.typography' => ['sometimes', 'array'],
            'settings.design.typography.font_file_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.design.typography.font_file_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'settings.design.typography.font_file_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.brand_partner' => ['sometimes', 'array'],
            'settings.brand_partner.professional_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('professionals', 'id')->where(function ($query) {
                    $query->where('professional_type', 'brand');
                }),
            ],
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            'settings.booking_mode' => ['sometimes', 'string', Rule::in(['manual', 'smart'])],
            'settings.manual_booking_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.selected_products' => ['prohibited'],

            // If you plan staff-only overrides later, allow this now.
            // Your UpdateSiteAction can choose to honor it only for staff.
            'force_publish' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'subdomain.regex' => 'The subdomain must contain only lowercase letters, numbers, and hyphens, and cannot start or end with a hyphen.',
            'subdomain.unique' => 'This subdomain is already taken.',
            'subdomain.min' => 'The subdomain must be at least 3 characters.',
            'subdomain.max' => 'The subdomain cannot exceed 63 characters.',
            'settings.selected_products.prohibited' => 'Use /api/store/featured-products for product selections.',
        ];
    }
}
