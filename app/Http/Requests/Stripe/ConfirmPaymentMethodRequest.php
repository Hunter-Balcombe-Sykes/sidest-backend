<?php

namespace App\Http\Requests\Stripe;

use App\Http\Requests\BaseFormRequest;

class ConfirmPaymentMethodRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'string'],
        ];
    }
}
