<?php

namespace App\Http\Requests\Api\Professional\Customer;

use App\Http\Requests\BaseFormRequest;

class UpdateCustomerRequest extends BaseFormRequest
{

    public function rules(): array
    {
        return [
            'full_name'  => ['sometimes', 'required', 'string', 'max:255'],
            'email'      => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes'      => ['sometimes', 'nullable', 'string'],
            'source'     => ['sometimes', 'nullable', 'string', 'max:225'],
            'external_id'=> ['sometimes', 'nullable', 'string', 'max:255'],
            'marketing_opt_in_cached' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
