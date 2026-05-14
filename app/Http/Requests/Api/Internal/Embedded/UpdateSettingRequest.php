<?php

namespace App\Http\Requests\Api\Internal\Embedded;

use App\Http\Requests\BaseFormRequest;

// Validates the patch-single-setting payload for EmbeddedSetupController@updateSetting.
// `key` is constrained to the three settings the wizard is allowed to mutate;
// the controller switches on this value to dispatch to BrandStoreSettings or BrandProfile.
//
// `value` rules are key-aware: when key=default_commission_rate we enforce
// numeric 0..100 inclusive (matching the DB CHECK on brand_store_settings.
// default_commission_rate). Without this guard a non-numeric value silently
// (float)-casts to 0 and an out-of-range value surfaces as a 500 via the DB
// constraint instead of a clean 422.
class UpdateSettingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $valueRules = $this->input('key') === 'default_commission_rate'
            ? ['required', 'numeric', 'min:0', 'max:100']
            : ['required', 'string'];

        return [
            'key' => ['required', 'string', 'in:default_commission_rate,theme_id,setup_complete'],
            'value' => $valueRules,
        ];
    }
}
