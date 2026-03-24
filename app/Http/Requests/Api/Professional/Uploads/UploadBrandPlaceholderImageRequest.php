<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;

class UploadBrandPlaceholderImageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'image' => [
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
            'image.mimes' => 'Placeholder image must be a JPEG, PNG, or WebP image.',
            'image.max' => 'Placeholder image must be smaller than 5 MB.',
        ];
    }
}
