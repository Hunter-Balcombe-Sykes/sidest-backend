<?php

namespace App\Http\Requests\Api\Professional\Services;

use App\Http\Requests\BaseFormRequest;

class UpdateServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
