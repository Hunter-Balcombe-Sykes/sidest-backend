<?php

namespace App\Http\Requests\Api\Professional\Uploads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrepareUploadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $professional = $this->attributes->get('professional');
        $proId = $professional?->id;
        $bucket = (string) config('comet.media_bucket', 'media');

        $proPrefix = $proId ? "professionals/{$proId}/" : 'professionals/';

        return [
            'type' => [
                'required',
                'string',
                Rule::in(['icon', 'headshot', 'banner', 'gallery']),
            ],
            'content_type' => [
                'required',
                'string',
                Rule::in(['image/jpeg', 'image/png', 'image/webp']),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->type ?? null)) {
            $this->merge(['type' => strtolower(trim($this->type))]);
        }
        if (is_string($this->content_type ?? null)) {
            $this->merge(['content_type' => strtolower(trim($this->content_type))]);
        }
    }

}
