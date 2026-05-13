<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class UpdateBrandStoreSettingsRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStrings(['accent_color', 'theme_variant', 'product_image_ratio']);
    }

    public function rules(): array
    {
        // Allow payout_hold_days=0 only when the testing flag is on, so brands
        // can pick "Instant Payout [TESTING ONLY]" in dev environments without
        // accidentally enabling it in prod.
        $payoutHoldValues = config('partna.store.allow_instant_payout_for_testing', false)
            ? '0,7,14,28'
            : '7,14,28';

        return [
            'default_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payout_hold_days' => ['sometimes', 'integer', 'in:'.$payoutHoldValues],
            'accent_color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_variant' => ['sometimes', 'nullable', 'string', 'max:50'],
            'product_image_ratio' => ['sometimes', 'nullable', 'string', 'in:1/1,4/5'],
            'custom_photos_enabled' => ['sometimes', 'boolean'],
            'custom_photo_position' => ['sometimes', 'string', 'in:before,after,mixed'],
            'theme_id' => ['sometimes', 'integer', 'in:1,2,3,4,5'],
            'oxygen_deployment_token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'oxygen_storefront_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hydrogen_install_confirmed' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'accent_color.regex' => 'The accent color must be a valid hex color (e.g., #ff0000).',
            'payout_hold_days.in' => 'Hold period must be 7, 14, or 28 days (or 0 in test environments where instant payout is enabled).',
        ];
    }
}
