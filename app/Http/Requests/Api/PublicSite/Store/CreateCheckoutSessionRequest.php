<?php

namespace App\Http\Requests\Api\PublicSite\Store;

use App\Http\Requests\BaseFormRequest;

class CreateCheckoutSessionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'line_items' => ['sometimes', 'array', 'max:100'],
            'line_items.*.brand_product_id' => ['sometimes', 'nullable', 'uuid'],
            'line_items.*.shopify_product_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_items.*.shopify_variant_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_items.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'line_items.*.quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'line_items.*.unit_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'line_items.*.line_total_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'context' => ['sometimes', 'array'],
        ];
    }
}
