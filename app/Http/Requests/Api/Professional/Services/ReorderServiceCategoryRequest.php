<?php

namespace App\Http\Requests\Api\Professional\Services;

use App\Http\Requests\BaseFormRequest;

class ReorderServiceCategoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
