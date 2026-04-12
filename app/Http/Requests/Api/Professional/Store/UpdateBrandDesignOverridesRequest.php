<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

// V2: Validates brand design token overrides. Each key is optional; nullable values clear that override.
// Fonts accept a string family name (most common case) — URLs are never user-provided, only discovered from Shopify theme sync.
class UpdateBrandDesignOverridesRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStrings([
            'primary_color',
            'secondary_color',
            'background_color',
            'text_color',
            'border_radius',
            'border_width',
            'button_background',
            'button_text_color',
            'heading_font',
            'body_font',
        ]);
    }

    public function rules(): array
    {
        $hexRule = ['sometimes', 'nullable', 'string', 'max:9', 'regex:/^#[0-9a-fA-F]{3,8}$/'];
        $lengthRule = ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^(0|-?\d+(\.\d+)?(px|rem|em|%)?)$/i'];
        $fontRule = ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9\s\-\.]+$/'];

        return [
            'primary_color' => $hexRule,
            'secondary_color' => $hexRule,
            'background_color' => $hexRule,
            'text_color' => $hexRule,
            'button_background' => $hexRule,
            'button_text_color' => $hexRule,
            'border_radius' => $lengthRule,
            'border_width' => $lengthRule,
            'heading_font' => $fontRule,
            'body_font' => $fontRule,
        ];
    }

    public function messages(): array
    {
        return [
            'primary_color.regex' => 'The primary color must be a valid hex color (e.g., #ff0000).',
            'secondary_color.regex' => 'The secondary color must be a valid hex color.',
            'background_color.regex' => 'The background color must be a valid hex color.',
            'text_color.regex' => 'The text color must be a valid hex color.',
            'button_background.regex' => 'The button background must be a valid hex color.',
            'button_text_color.regex' => 'The button text color must be a valid hex color.',
            'border_radius.regex' => 'The border radius must be a CSS length value (e.g., 8px, 0.5rem).',
            'border_width.regex' => 'The border width must be a CSS length value (e.g., 1px).',
            'heading_font.regex' => 'The heading font must contain only letters, numbers, spaces, hyphens, and dots.',
            'body_font.regex' => 'The body font must contain only letters, numbers, spaces, hyphens, and dots.',
        ];
    }
}
