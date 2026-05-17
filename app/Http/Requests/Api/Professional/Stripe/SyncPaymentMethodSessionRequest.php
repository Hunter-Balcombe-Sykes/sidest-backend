<?php

namespace App\Http\Requests\Api\Professional\Stripe;

use App\Http\Requests\BaseFormRequest;

class SyncPaymentMethodSessionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return ['session_id' => ['required', 'string', 'starts_with:cs_']];
    }
}
