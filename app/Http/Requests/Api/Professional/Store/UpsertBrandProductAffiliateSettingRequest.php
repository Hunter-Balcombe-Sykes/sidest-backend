<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class UpsertBrandProductAffiliateSettingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['required', 'uuid'],
            'affiliate_professional_id' => ['required', 'uuid'],
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.brand_product_id' => ['required', 'uuid'],
            'settings.*.commission_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.*.discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.*.custom_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
