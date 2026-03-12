<?php

namespace App\Http\Requests\Api\Enterprise;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class CreateEnterpriseRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'handle' => [
                'sometimes',
                'nullable',
                'string',
                'max:63',
                Rule::unique('enterprises', 'handle')->where(function ($query) {
                    $query->whereNull('deleted_at');
                }),
            ],
            'enterprise_type' => ['required', 'string', Rule::in(['promoter', 'salon', 'barbershop'])],
            'subscription_tier' => ['sometimes', 'nullable', 'string', 'max:100'],

            'primary_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'public_contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'public_contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:5'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],

            'location_street_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_postcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_country' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings([
            'name',
            'handle',
            'enterprise_type',
            'subscription_tier',
            'phone',
            'public_contact_number',
            'country_code',
            'timezone',
            'location_street_address',
            'location_city',
            'location_state',
            'location_postcode',
            'location_country',
        ]);
        $this->sanitizeEmails(['primary_email', 'public_contact_email']);

        $name = trim((string) $this->input('name', ''));
        $handle = trim((string) $this->input('handle', ''));
        if ($handle === '' && $name !== '') {
            $handle = Str::slug($name);
        }

        $enterpriseType = $this->input('enterprise_type');
        $merge = [
            'handle' => $handle !== '' ? Str::lower($handle) : null,
            'enterprise_type' => is_string($enterpriseType) && trim($enterpriseType) !== ''
                ? Str::lower(trim($enterpriseType))
                : $enterpriseType,
        ];

        $phone = $this->input('phone');
        if (is_string($phone)) {
            $phone = preg_replace('/[^\d+]/', '', $phone);
            $merge['phone'] = $phone === '' ? null : $phone;
        }

        $publicPhone = $this->input('public_contact_number');
        if (is_string($publicPhone)) {
            $publicPhone = preg_replace('/[^\d+]/', '', $publicPhone);
            $merge['public_contact_number'] = $publicPhone === '' ? null : $publicPhone;
        }

        $this->merge($merge);
    }
}
