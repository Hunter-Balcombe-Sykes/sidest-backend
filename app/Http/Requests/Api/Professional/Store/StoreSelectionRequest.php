<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class StoreSelectionRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimStrings(['product_gid']);
    }

    public function rules(): array
    {
        return [
            'product_gid' => ['required', 'string', 'max:100', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_gid.regex' => 'The product GID must be a valid Shopify product GID (e.g., gid://shopify/Product/12345).',
        ];
    }
}
