<?php

namespace App\Http\Requests\Api\PublicSite\Store;

use App\Http\Requests\BaseFormRequest;

class StripeCheckoutRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'checkout_session_token' => ['required', 'string', 'max:255'],
            'success_url' => ['required', 'url'],
            'cancel_url' => ['required', 'url'],
            'customer' => ['required', 'array'],
            'customer.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.email' => ['required', 'string', 'email:rfc', 'max:255'],
            'customer.phone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.address1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.address2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.zip' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
