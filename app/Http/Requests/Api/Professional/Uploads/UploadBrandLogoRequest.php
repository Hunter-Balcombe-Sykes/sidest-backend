<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// V2: Validates brand logo upload — JPEG, PNG, or WebP image file up to 5 MB.
// Optional `variant` field selects the slot: 'full' (default) or 'square'.
class UploadBrandLogoRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'logo' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,webp',
                'max:5120',
            ],
            'variant' => ['sometimes', 'string', Rule::in(['full', 'square'])],
        ];
    }

    public function messages(): array
    {
        return [
            'logo.mimes' => 'Brand logo must be a JPEG, PNG, or WebP image.',
            'logo.max' => 'Brand logo must be smaller than 5 MB.',
            'variant.in' => 'Logo variant must be one of: full, square.',
        ];
    }
}
