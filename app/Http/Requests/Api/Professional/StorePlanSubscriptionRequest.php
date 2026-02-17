<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;

class StorePlanSubscriptionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'exists:plans,id'],
            'trial_period_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
