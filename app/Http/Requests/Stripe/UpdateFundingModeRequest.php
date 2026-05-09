<?php

namespace App\Http\Requests\Stripe;

use App\Http\Requests\BaseFormRequest;

class UpdateFundingModeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:auto_charge,manual_topup'],
        ];
    }
}
