<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;

class OnboardRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'return_url' => ['required', 'url', $this->allowedRedirectRule()],
            'refresh_url' => ['required', 'url', $this->allowedRedirectRule()],
        ];
    }
}
