<?php

namespace App\Http\Requests\Stripe;

use App\Http\Requests\BaseFormRequest;

class OnboardRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'return_url' => ['required', 'url'],
            'refresh_url' => ['required', 'url'],
        ];
    }
}
