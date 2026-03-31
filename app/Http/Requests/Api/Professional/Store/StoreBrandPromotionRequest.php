<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\Validator;

class StoreBrandPromotionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'affiliate_scope' => ['required', 'string', 'in:all,segments,affiliates'],
            'affiliate_ids' => ['nullable', 'array', 'max:200', 'required_if:affiliate_scope,affiliates'],
            'affiliate_ids.*' => ['uuid'],
            'affiliate_segment_ids' => ['nullable', 'array', 'max:20', 'required_if:affiliate_scope,segments'],
            'affiliate_segment_ids.*' => ['uuid'],
            'product_scope' => ['required', 'string', 'in:all,products'],
            'product_ids' => ['nullable', 'array', 'max:200', 'required_if:product_scope,products'],
            'product_ids.*' => ['uuid'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();

            $hasCommissionRate = array_key_exists('commission_rate', $data)
                && $data['commission_rate'] !== null
                && $data['commission_rate'] !== '';
            $hasDiscountRate = array_key_exists('discount_rate', $data)
                && $data['discount_rate'] !== null
                && $data['discount_rate'] !== '';

            if (! $hasCommissionRate && ! $hasDiscountRate) {
                $v->errors()->add('commission_rate', 'At least one of commission_rate or discount_rate is required.');
            }
        });
    }
}
