<?php

namespace App\Http\Requests\Api\Professional\ImageGallery;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreGalleryImageRequest extends BaseFormRequest
{

    public function rules(): array
    {
        $professional = $this->attributes->get('professional');
        $siteId = $professional?->site?->id;

        $bucket = (string) config('comet.media_bucket', 'media');
        $prefix = $siteId ? "sites/{$siteId}/gallery/" : 'sites/';


        return [
            'bucket' => ['required', 'string', Rule::in([$bucket])],
            'path' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($prefix) {
                    if (!is_string($value) || !str_starts_with($value, $prefix)) {
                        $fail('Invalid path for gallery image.');
                    }
                },
            ],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
