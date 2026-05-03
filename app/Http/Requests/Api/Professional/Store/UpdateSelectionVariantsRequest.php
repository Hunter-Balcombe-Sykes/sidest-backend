<?php

namespace App\Http\Requests\Api\Professional\Store;

use App\Http\Requests\BaseFormRequest;

/**
 * Validation for PATCH /affiliate/selections/{productGid}/variants.
 *
 * An affiliate can narrow the set of a product's variants shown on their
 * storefront. NULL or an empty array resets the selection back to "all
 * brand-enabled variants" (the default); a populated array stores an explicit
 * subset. Per-GID membership is validated in the controller against the
 * brand's active catalog — the rules here only enforce shape + GID format.
 */
class UpdateSelectionVariantsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'brand_professional_id' => ['required', 'uuid'],
            'variant_gids' => ['sometimes', 'nullable', 'array'],
            'variant_gids.*' => ['string', 'regex:/^gid:\/\/shopify\/ProductVariant\/\d+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'variant_gids.*.regex' => 'Each variant GID must be a valid Shopify variant GID (e.g., gid://shopify/ProductVariant/12345).',
        ];
    }
}
