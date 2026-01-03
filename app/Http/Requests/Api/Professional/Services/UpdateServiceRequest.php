<?php

namespace App\Http\Requests\Api\Professional\Services;

use App\Http\Requests\BaseFormRequest;

class UpdateServiceRequest extends BaseFormRequest
{

    public function rules(): array
    {
        return [
            'title'            => ['sometimes', 'required', 'string', 'max:255'],
            'category'         => ['sometimes', 'nullable', 'string', 'max:80'],
            'description'      => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_cents'      => ['sometimes', 'required', 'integer', 'min:0'],
            'currency_code'    => ['sometimes', 'nullable', 'string', 'size:3'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active'        => ['sometimes', 'boolean'],
        ];
    }
}
