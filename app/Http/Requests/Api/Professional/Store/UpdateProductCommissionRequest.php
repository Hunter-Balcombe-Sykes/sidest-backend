<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class UpdateProductCommissionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'commission_override' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
