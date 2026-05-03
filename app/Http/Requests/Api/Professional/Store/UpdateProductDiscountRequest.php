<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class UpdateProductDiscountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'affiliate_discount_pct' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
