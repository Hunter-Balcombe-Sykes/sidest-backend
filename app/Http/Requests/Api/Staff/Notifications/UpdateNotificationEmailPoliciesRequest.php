<?php

namespace App\Http\Requests\Api\Staff\Notifications;

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationEmailPoliciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validCategories = implode(',', NotificationPublisher::CATEGORIES);

        return [
            'policies'            => ['required', 'array', 'min:1'],
            'policies.*.category' => ['required', 'string', 'in:' . $validCategories],
            'policies.*.mode'     => ['required', 'string', 'in:default,force_on,force_off'],
        ];
    }
}
