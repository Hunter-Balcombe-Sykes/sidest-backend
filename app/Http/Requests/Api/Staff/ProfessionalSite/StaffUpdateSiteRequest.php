<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite;

use App\Models\Core\Site\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StaffUpdateSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // You already protect routes with middleware (supabase.jwt + staff/admin).
        return true;
    }

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

            'banner_bucket' => ['sometimes', 'nullable', 'string', 'max:255'],
            'banner_path'   => ['sometimes', 'nullable', 'string', 'max:255'],

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
        ];
    }
}
