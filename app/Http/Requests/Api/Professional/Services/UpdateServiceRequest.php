<?php

namespace App\Http\Requests\Api\Professional\Services;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

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
