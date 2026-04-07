<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;
use App\Models\Billing\Plan;

// V2: Validates new plan subscription creation — requires plan ID plus success/cancel URLs for paid plans.
class StorePlanSubscriptionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'exists:plans,id'],
            'success_url' => ['required_unless:plan_id,' . $this->freePlanId(), 'nullable', 'url'],
            'cancel_url' => ['required_unless:plan_id,' . $this->freePlanId(), 'nullable', 'url'],
        ];
    }

    private function freePlanId(): string
    {
        return Plan::where('plan_key', 'free')->value('id') ?? '';
    }
}
