<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

// V2: Validates image or video upload to a pool — enforces one-of constraint, file type/size limits, and video feature flag check.
class UploadImageRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $imageMaxKb = (int) config('sidest.image_max_upload_size', 10240);
        $videoMaxKb = (int) config('sidest.video_max_upload_size', 512000);

        return [
            'pool' => [
                'required',
                'string',
                Rule::in(['gallery', 'content']),
            ],
            // Either `image` or `video` must be provided (not both). The after-validator
            // enforces the one-of constraint; individual rules run only when the field exists.
            'image' => [
                'sometimes',
                'nullable',
                'file',
                'image',
                'mimes:jpeg,png,webp',
                "max:{$imageMaxKb}",
            ],
            'video' => [
                'sometimes',
                'nullable',
                'file',
                'mimes:mp4,mov,webm,avi',
                "max:{$videoMaxKb}",
            ],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $hasImage = $this->hasFile('image');
            $hasVideo = $this->hasFile('video');

            if ($hasImage && $hasVideo) {
                $v->errors()->add('image', 'Provide either an image or a video, not both.');

                return;
            }

            if (! $hasImage && ! $hasVideo) {
                $v->errors()->add('image', 'An image or video file is required.');

                return;
            }

            if ($hasVideo && ! config('sidest.video_uploads_enabled', false)) {
                $v->errors()->add('video', 'Video uploads are not currently enabled.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->pool ?? null)) {
            $this->merge(['pool' => strtolower(trim($this->pool))]);
        }
    }

    public function messages(): array
    {
        $imageMaxMb = round(((int) config('sidest.image_max_upload_size', 10240)) / 1024, 1);
        $videoMaxMb = round(((int) config('sidest.video_max_upload_size', 512000)) / 1024, 0);

        return [
            'pool.in' => 'Pool must be "gallery" or "content".',
            'image.max' => "Image must be smaller than {$imageMaxMb} MB.",
            'image.mimes' => 'Image must be JPEG, PNG, or WebP.',
            'image.image' => 'The file must be a valid image.',
            'video.max' => "Video must be smaller than {$videoMaxMb} MB.",
            'video.mimes' => 'Video must be MP4, MOV, WebM, or AVI.',
        ];
    }
}
