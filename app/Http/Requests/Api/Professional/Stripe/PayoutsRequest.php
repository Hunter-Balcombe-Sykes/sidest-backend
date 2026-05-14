<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class PayoutsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['brand', 'affiliate'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(['pending', 'processing', 'completed', 'failed', 'cancelled'])],
            'cursor' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
