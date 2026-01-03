<?php

namespace App\Http\Requests\Api\Professional\ImageGallery;

use App\Http\Requests\BaseFormRequest;

class ReorderGalleryImageRequest extends BaseFormRequest
{

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
