<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class RemoveBrandProductAffiliateSettingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['required', 'uuid'],
            'affiliate_professional_id' => ['required', 'uuid'],
            'brand_product_ids' => ['sometimes', 'array'],
            'brand_product_ids.*' => ['uuid'],
        ];
    }
}
