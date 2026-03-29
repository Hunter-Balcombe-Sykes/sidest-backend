<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class UploadProductMediaRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $imageMaxKb = (int) config('comet.image_max_upload_size', 10240);

        return [
            'image'    => ['required', 'file', 'image', 'mimes:jpeg,png,webp', "max:{$imageMaxKb}"],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        $imageMaxMb = round(((int) config('comet.image_max_upload_size', 10240)) / 1024, 1);

        return [
            'image.required' => 'An image file is required.',
            'image.max'      => "Image must be smaller than {$imageMaxMb} MB.",
            'image.mimes'    => 'Image must be JPEG, PNG, or WebP.',
            'image.image'    => 'The file must be a valid image.',
        ];
    }
}
