<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

/**
 * Validation for the bulk PATCH /brand/catalog/{productGid}/metafields endpoint.
 *
 * All fields are 'sometimes' — only keys present in the request are touched. Nullable
 * fields use null as the "clear this override" signal; the controller deletes the
 * underlying Shopify metafield rather than writing an empty value, so the dynamic
 * default kicks back in. enabled_variant_gids is additionally validated in the
 * controller for variant-belongs-to-product correctness before being written.
 *
 * See docs/brand-catalog-v2.md §3 for the full metafield reference.
 */
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
