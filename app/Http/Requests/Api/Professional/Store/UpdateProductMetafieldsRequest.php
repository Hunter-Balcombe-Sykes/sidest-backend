<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

class UpdateProductMetafieldsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'active' => ['sometimes', 'boolean'],
            'commission_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'affiliate_discount_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'custom_photos_enabled' => ['sometimes', 'nullable', 'boolean'],
            'enabled_variant_gids' => ['sometimes', 'nullable', 'array'],
            'enabled_variant_gids.*' => ['string', 'regex:/^gid:\/\/shopify\/ProductVariant\/\d+$/'],
        ];
    }
}
