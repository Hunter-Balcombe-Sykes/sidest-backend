<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class PaymentPreferenceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['card', 'becs'])],
        ];
    }
}
