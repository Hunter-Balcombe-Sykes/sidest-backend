<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;

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
        ];
    }

    public function messages(): array
    {
        return [
            'logo.mimes' => 'Brand logo must be a JPEG, PNG, or WebP image.',
            'logo.max' => 'Brand logo must be smaller than 5 MB.',
        ];
    }
}
