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
            'accent_color' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_variant' => ['sometimes', 'nullable', 'string', 'max:50'],
            'product_image_ratio' => ['sometimes', 'nullable', 'string', 'in:1/1,4/5'],
        ];
    }

    public function messages(): array
    {
        return [
            'accent_color.regex' => 'The accent color must be a valid hex color (e.g., #ff0000).',
        ];
    }
}
