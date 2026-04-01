<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class CloneBrandPromotionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'commission_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'discount_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'affiliate_scope' => ['sometimes', 'string', 'in:all,segments,affiliates'],
            'affiliate_ids' => ['sometimes', 'nullable', 'array', 'max:200'],
            'affiliate_ids.*' => ['uuid'],
            'affiliate_segment_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'affiliate_segment_ids.*' => ['uuid'],
            'product_scope' => ['sometimes', 'string', 'in:all,products'],
            'product_ids' => ['sometimes', 'nullable', 'array', 'max:200'],
            'product_ids.*' => ['uuid'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
