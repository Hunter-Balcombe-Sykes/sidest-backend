<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class UpdateBrandAffiliateSegmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['sometimes', 'uuid'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'criteria' => ['sometimes', 'string', 'in:highest_revenue,lowest_revenue,most_orders,fewest_orders,highest_commission,lowest_commission,newest,professional_type'],
            'size' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'lookback_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'professional_type_filter' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
