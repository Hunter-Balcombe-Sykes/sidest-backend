<?php

namespace App\Http\Requests\Stripe;

use App\Http\Requests\BaseFormRequest;

class CreatePaymentMethodSetupRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'success_url' => ['required', 'url'],
            'cancel_url'  => ['required', 'url'],
        ];
    }
}
