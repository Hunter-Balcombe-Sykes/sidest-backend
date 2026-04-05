<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;

// V2: Validates new plan subscription creation — requires a valid plan ID with optional trial period.
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
