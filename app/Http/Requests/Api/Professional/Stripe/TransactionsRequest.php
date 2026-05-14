<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class TransactionsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['brand', 'affiliate'])],
            'date_from' => ['nullable', 'date'],
            // after_or_equal lets the frontend send `date_from === date_to` to filter a single day.
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'type' => ['nullable', Rule::in(['charge', 'refund', 'transfer', 'reversal', 'all'])],
            'cursor' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
