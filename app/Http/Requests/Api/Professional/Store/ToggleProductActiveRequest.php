<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class ToggleProductActiveRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
        ];
    }
}
