<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

class StoreBrandAffiliateSegmentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['required', 'uuid'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('retail.brand_affiliate_segments', 'name')->where(
                    fn ($query) => $query->where('brand_professional_id', $this->input('brand_professional_id'))
                ),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'criteria' => ['required', 'string', 'in:highest_revenue,lowest_revenue,most_orders,fewest_orders,highest_commission,lowest_commission,newest,professional_type'],
            'size' => ['required', 'integer', 'min:0', 'max:200'],
            'lookback_days' => ['nullable', 'integer', 'min:1'],
            'professional_type_filter' => ['nullable', 'string', 'max:100', 'required_if:criteria,professional_type'],
        ];
    }
}
