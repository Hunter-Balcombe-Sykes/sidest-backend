<?php

namespace App\Http\Requests\Api\Professional\Notifications;

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Foundation\Http\FormRequest;

// V2: Validates notification email preference updates — array of category/enabled pairs constrained to known categories.
class UpdateNotificationEmailPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validCategories = implode(',', NotificationPublisher::CATEGORIES);

        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.category' => ['required', 'string', 'in:'.$validCategories],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }
}
