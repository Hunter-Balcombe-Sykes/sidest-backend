<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\NormalizesProfessionalType;
use Illuminate\Validation\Rule;

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

            'primary_email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone'         => ['sometimes', 'required', 'string', 'max:50'],
            'public_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'public_contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],

            'country_code'  => ['sometimes', 'nullable', 'string', 'min:2', 'max:3'],
            'timezone'      => ['sometimes', 'nullable', 'string', 'max:64'],
            'professional_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_keys(config('comet.professional_types', []))),
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
