<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\Validator;

class UpdateBrandPromotionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['sometimes', 'uuid'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
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

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();

            // If ends_at is being explicitly set to a past date, reject.
            if (! empty($data['ends_at'])) {
                try {
                    $endsAt = new \DateTime($data['ends_at']);
                    if ($endsAt < new \DateTime()) {
                        $v->errors()->add('ends_at', 'Cannot update a promotion that has already ended.');
                    }
                } catch (\Exception) {
                    // Date parsing error is handled by the 'date' rule above.
                }
            }

            // ends_at must be after starts_at when both are provided.
            if (! empty($data['starts_at']) && ! empty($data['ends_at'])) {
                try {
                    $startsAt = new \DateTime($data['starts_at']);
                    $endsAt = new \DateTime($data['ends_at']);
                    if ($endsAt <= $startsAt) {
                        $v->errors()->add('ends_at', 'ends_at must be after starts_at.');
                    }
                } catch (\Exception) {
                    // Handled by 'date' rules above.
                }
            }
        });
    }
}
