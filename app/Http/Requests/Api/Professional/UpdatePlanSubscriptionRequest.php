<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;

class UpdatePlanSubscriptionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'exists:plans,id'],
        ];
    }
}
