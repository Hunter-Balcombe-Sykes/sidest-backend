<?php

namespace App\Http\Requests\Stripe;

use App\Http\Requests\BaseFormRequest;

class CreateTopUpCheckoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount_cents'  => ['required', 'integer', 'min:1000', 'max:10000000'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'success_url'   => ['required', 'url'],
            'cancel_url'    => ['required', 'url'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_cents.min' => 'Minimum top-up is $10.00 (1,000 cents).',
            'amount_cents.max' => 'Maximum top-up is $100,000 (10,000,000 cents).',
        ];
    }
}
