<?php

namespace App\Http\Requests\Api\Professional\Services;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title'            => ['required', 'string', 'max:255'],
            'category'         => ['nullable', 'string', 'max:80'],
            'description'      => ['nullable', 'string', 'max:2000'],
            'price_cents'      => ['required', 'integer', 'min:0'],
            'currency_code'    => ['nullable', 'string', 'size:3'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active'        => ['sometimes', 'boolean'],
        ];
    }
}
