<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;

class CreatePaymentMethodSetupRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'success_url' => ['required', 'url', $this->allowedRedirectRule()],
            'cancel_url' => ['required', 'url', $this->allowedRedirectRule()],
        ];
    }
}
