<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;
use App\Services\Professional\ConfirmationPreferenceService;

// V2: Validates confirmation preference toggles — boolean flags for delete customer, delete media, and unselect product actions.
class UpdateConfirmationPreferenceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            ConfirmationPreferenceService::ACTION_DELETE_CUSTOMER => ['sometimes', 'boolean'],
            ConfirmationPreferenceService::ACTION_DELETE_MEDIA => ['sometimes', 'boolean'],
            ConfirmationPreferenceService::ACTION_UNSELECT_PRODUCT => ['sometimes', 'boolean'],
        ];
    }
}
