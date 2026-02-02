<?php

namespace App\Http\Requests\Api\Professional\Services;

use App\Http\Requests\BaseFormRequest;

class StoreServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
