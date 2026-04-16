<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\NormalizesProfessionalType;
use Illuminate\Validation\Rule;

// V2: Validates professional profile updates — display name, contact info, location, type normalization, and email/phone sanitization.
class UpdateProfessionalRequest extends BaseFormRequest
{
    use NormalizesProfessionalType;

    public function rules(): array
    {
        return [
            // keep handle out of this endpoint (handle changes should be a dedicated flow)
            'display_name'  => ['sometimes', 'required', 'string', 'max:255'],
            'bio'           => ['sometimes', 'nullable', 'string', 'max:2000'],

            'first_name'    => ['sometimes', 'required', 'string', 'max:255'],
            'last_name'     => ['sometimes', 'nullable', 'string', 'max:255'],

            'primary_email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('professionals', 'primary_email')
                    ->ignore($this->attributes->get('professional')?->id, 'id'),
            ],
            'phone'         => ['sometimes', 'required', 'string', 'max:50'],
            'public_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'public_contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],

            // ISO 3166-1 alpha-2 only. Normalised to upper-case in
            // prepareForValidation before this rule runs. Matches the
            // tightened BootstrapRequest format.
            'country_code'  => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'timezone'      => ['sometimes', 'nullable', 'string', 'max:64'],
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
        ];
    }

    protected function prepareForValidation(): void
    {
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

        // Treat empty string as null (prevents “only one user can have empty email” style issues)
        if ($value === '') {
            return null;
        }

        return mb_strtolower($value);
    }

}
