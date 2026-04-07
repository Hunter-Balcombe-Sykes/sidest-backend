<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;

// V2: Validates plan subscription changes — requires plan ID plus success/cancel URLs for free-to-paid transitions.
class UpdatePlanSubscriptionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'exists:plans,id'],
            'success_url' => ['sometimes', 'nullable', 'url'],
            'cancel_url' => ['sometimes', 'nullable', 'url'],
        ];
    }
}
