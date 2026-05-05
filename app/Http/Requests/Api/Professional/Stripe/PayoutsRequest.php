<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class PayoutsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['brand', 'affiliate'])],
        ];
    }
}
