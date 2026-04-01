<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class RefreshBrandAffiliateSegmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ];
    }
}
