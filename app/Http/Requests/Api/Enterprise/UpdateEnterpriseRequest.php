<?php

namespace App\Http\Requests\Api\Enterprise;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class UpdateEnterpriseRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $uid = $this->attributes->get('supabase_uid');

        $ignoreId = null;
        if (is_string($uid) && $uid !== '') {
            $ignoreId = \App\Models\Core\Enterprise\Enterprise::query()
                ->where('auth_user_id', $uid)
                ->whereNull('deleted_at')
                ->value('id');
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'handle' => [
                'sometimes',
                'nullable',
                'string',
                'max:63',
                Rule::unique('enterprises', 'handle')
                    ->ignore($ignoreId, 'id')
                    ->where(function ($query) {
                        $query->whereNull('deleted_at');
                    }),
            ],
            'enterprise_type' => ['sometimes', 'required', 'string', Rule::in(['promoter', 'salon', 'barbershop'])],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive', 'suspended'])],
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
            'status',
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

        $merge = [];

        if ($this->has('handle')) {
            $handle = trim((string) $this->input('handle', ''));
            $merge['handle'] = $handle !== '' ? Str::lower($handle) : null;
        }

        if ($this->has('enterprise_type')) {
            $value = $this->input('enterprise_type');
            $merge['enterprise_type'] = is_string($value) && trim($value) !== ''
                ? Str::lower(trim($value))
                : $value;
        }

        if ($this->has('status')) {
            $value = $this->input('status');
            $merge['status'] = is_string($value) && trim($value) !== ''
                ? Str::lower(trim($value))
                : $value;
        }

        if ($this->has('phone')) {
            $phone = $this->input('phone');
            if (is_string($phone)) {
                $phone = preg_replace('/[^\d+]/', '', $phone);
                $merge['phone'] = $phone === '' ? null : $phone;
            }
        }

        if ($this->has('public_contact_number')) {
            $publicPhone = $this->input('public_contact_number');
            if (is_string($publicPhone)) {
                $publicPhone = preg_replace('/[^\d+]/', '', $publicPhone);
                $merge['public_contact_number'] = $publicPhone === '' ? null : $publicPhone;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
