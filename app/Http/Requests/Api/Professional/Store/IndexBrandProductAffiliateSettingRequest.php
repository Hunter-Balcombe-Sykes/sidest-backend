<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class IndexBrandProductAffiliateSettingRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['required', 'uuid'],
            'affiliate_professional_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
