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
        return [
            'default_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payout_hold_days' => ['sometimes', 'integer', 'in:7,14,28'],
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
        ];
    }
}
