<?php

namespace App\Http\Requests\Api\Internal\Embedded;

use App\Http\Requests\BaseFormRequest;

// Validates the patch-single-setting payload for EmbeddedSetupController@updateSetting.
// `key` is constrained to the three settings the wizard is allowed to mutate;
// the controller switches on this value to dispatch to BrandStoreSettings or BrandProfile.
class UpdateSettingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'in:default_commission_rate,theme_id,setup_complete'],
            'value' => ['required', 'string'],
        ];
    }
}
