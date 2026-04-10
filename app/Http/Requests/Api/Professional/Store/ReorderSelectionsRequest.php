<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class ReorderSelectionsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $max = (int) config('sidest.store.max_featured_products', 10);

        return [
            'items' => ['required', 'array', 'min:1', "max:{$max}"],
            'items.*.product_gid' => ['required', 'string', 'max:100', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
            'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.product_gid.regex' => 'Each product GID must be a valid Shopify product GID.',
        ];
    }
}
