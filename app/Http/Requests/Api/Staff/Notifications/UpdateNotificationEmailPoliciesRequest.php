<?php

namespace App\Http\Requests\Api\Staff\Notifications;

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Foundation\Http\FormRequest;

// V2: Validates bulk update of notification email policies — requires an array of category/mode pairs with modes: default, force_on, or force_off.
class UpdateNotificationEmailPoliciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validCategories = implode(',', NotificationPublisher::categories());

        return [
            'policies' => ['required', 'array', 'min:1'],
            'policies.*.category' => ['required', 'string', 'in:'.$validCategories],
            'policies.*.mode' => ['required', 'string', 'in:default,force_on,force_off'],
        ];
    }
}
