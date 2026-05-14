<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class ExportsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['brand', 'affiliate'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(['pending', 'processing', 'completed', 'failed', 'cancelled'])],
            // AU financial year — eofy export filters orders to Jul 1 (fy-1) → Jun 30 (fy).
            'fy' => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ];
    }
}
