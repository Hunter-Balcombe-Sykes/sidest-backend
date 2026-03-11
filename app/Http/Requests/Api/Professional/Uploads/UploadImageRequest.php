<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class UploadImageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $maxKb = (int) config('comet.image_max_upload_size', 10240);

        return [
            'pool' => [
                'required',
                'string',
                Rule::in(['gallery', 'content']),
            ],
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,webp',
                "max:{$maxKb}",
            ],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->pool ?? null)) {
            $this->merge(['pool' => strtolower(trim($this->pool))]);
        }
    }

    public function messages(): array
    {
        $maxMb = round(((int) config('comet.image_max_upload_size', 10240)) / 1024, 1);

        return [
            'pool.in'     => 'Pool must be "gallery" or "content".',
            'image.max'   => "Image must be smaller than {$maxMb} MB.",
            'image.mimes' => 'Image must be JPEG, PNG, or WebP.',
            'image.image' => 'The file must be a valid image.',
        ];
    }
}
