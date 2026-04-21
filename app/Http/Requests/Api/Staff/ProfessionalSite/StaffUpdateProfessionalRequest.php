<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\NormalizesProfessionalType;
use App\Http\Requests\Concerns\ValidatesProfessionalAbout;
use Illuminate\Validation\Rule;

// V2: Validates staff update of a professional profile — supports display name, contact info, location, professional type, and phone normalization with PATCH semantics.
class StaffUpdateProfessionalRequest extends BaseFormRequest
{
    use NormalizesProfessionalType;
    use ValidatesProfessionalAbout;

    public function rules(): array
    {
        return array_merge([
            // profile-ish fields
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'primary_email' => ['sometimes', 'required', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:50'],
            'public_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'public_contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],

            // ISO 3166-1 alpha-2 only. Normalised to upper-case in
            // prepareForValidation before this rule runs.
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'professional_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_keys(config('sidest.professional_types', []))),
            ],

            // Location
            'location_street_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_postcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_country' => ['sometimes', 'nullable', 'string', 'max:255'],

            // optional staff-only flags (ONLY keep these if your DB/model actually has them)
            // 'is_suspended' => ['sometimes', 'boolean'],
            // 'admin_notes'  => ['sometimes', 'nullable', 'string', 'max:5000'],
        ], $this->aboutRules());
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $this->validateExperienceDateOrder($v);
        });
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAboutPayload();

        $phone = $this->input('phone');
        if (is_string($phone)) {
            $phone = trim($phone);
            $phone = preg_replace('/[^\d+]/', '', $phone); // keep digits and +
            $this->merge(['phone' => $phone === '' ? null : $phone]);
        }
        $public = $this->input('public_contact_number');
        if (is_string($public)) {
            $public = trim($public);
            $public = preg_replace('/[^\d+]/', '', $public); // keep digits and +
            $this->merge(['public_contact_number' => $public === '' ? null : $public]);
        }

        $merge = [];

        if ($this->has('primary_email')) {
            $merge['primary_email'] = $this->lowerOrNull($this->input('primary_email'));
        }

        if ($this->has('public_contact_email')) {
            $merge['public_contact_email'] = $this->lowerOrNull($this->input('public_contact_email'));
        }

        if ($this->has('professional_type')) {
            $professionalType = $this->input('professional_type');
            $merge['professional_type'] = $this->normalizeProfessionalTypeInput($professionalType);
        }

        // Upper-case country_code if supplied so the ISO alpha-2 validator
        // accepts lower-case input from older clients.
        if ($this->has('country_code')) {
            $cc = $this->input('country_code');
            if (is_string($cc)) {
                $cc = strtoupper(trim($cc));
                $merge['country_code'] = $cc === '' ? null : $cc;
            }
        }

        if ($merge) {
            $this->merge($merge);
        }
    }

    private function lowerOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_strtolower($value);
    }
}
