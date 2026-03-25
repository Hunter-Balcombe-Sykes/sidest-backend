<?php

namespace App\Http\Requests\Api\Professional;

use App\Http\Requests\BaseFormRequest;
use App\Services\Professional\ConfirmationPreferenceService;

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
