<?php

namespace App\Http\Requests\Api\Staff\ProfessionalSite;

use App\Http\Requests\BaseFormRequest;

class StaffUpdateCustomerRequest extends BaseFormRequest
{

    public function rules(): array
    {
        return [
            'full_name'   => ['sometimes', 'required', 'string', 'max:255'],
            'email'       => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'       => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes'       => ['sometimes', 'nullable', 'string'],
            'source'      => ['sometimes', 'nullable', 'string', 'max:225'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (is_string($phone)) {
            $phone = trim($phone);
            $phone = preg_replace('/[^\d+]/', '', $phone);
            $this->merge(['phone' => $phone === '' ? null : $phone]);
        }
    }
}
