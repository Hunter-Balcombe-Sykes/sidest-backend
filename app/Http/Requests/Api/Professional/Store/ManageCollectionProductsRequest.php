<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class ManageCollectionProductsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'product_gids' => ['required', 'array', 'min:1', 'max:50'],
            'product_gids.*' => ['required', 'string', 'max:100', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_gids.*.regex' => 'Each product GID must be a valid Shopify product GID.',
        ];
    }
}
