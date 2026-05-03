<?php

namespace App\Http\Requests\Api\Staff\Notifications;

use App\Http\Requests\BaseFormRequest;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Validation\Rule;

// V2: Validates bulk update of notification email policies — requires an array of category/mode pairs with modes: default, force_on, or force_off.
class UpdateNotificationEmailPoliciesRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'policies' => ['required', 'array', 'min:1'],
            'policies.*.category' => ['required', 'string', Rule::in(NotificationPublisher::categories())],
            'policies.*.mode' => ['required', 'string', 'in:default,force_on,force_off'],
        ];
    }
}
