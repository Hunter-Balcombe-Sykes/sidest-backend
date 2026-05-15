<?php

namespace App\Http\Requests\Api\Internal\Embedded;

use App\Http\Requests\BaseFormRequest;

// Validates the patch-single-field payload for EmbeddedProductSettingsController@update.
//
// The controller saves per-field on change (no monolithic Save button). Rules:
//   - product_gid is the Shopify Product GID — must match the canonical format
//     so we don't push garbage into Admin GraphQL and surface a 422 with a
//     useful message instead of a generic Shopify userError.
//   - field is constrained to the seven supported keys; the controller's match
//     block dispatches on this. Without the in: allowlist a typo'd field would
//     throw \InvalidArgumentException → 422 with a leaky message.
//   - value rules are field-aware:
//       * commission_override / affiliate_discount_pct → numeric 0..100
//         (matches the dashboard cap and the metafield consumers — a non-numeric
//         value would silently json_encode to "" and clear the override).
//       * active / custom_photos_enabled / add_to_favourites / add_to_default →
//         boolean (passes through filter_var(FILTER_VALIDATE_BOOLEAN) so "true"/
//         "false"/0/1 all work).
//       * disabled_variant_gids → array of strings (controller iterates).
class UpdateProductSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $field = (string) $this->input('field', '');

        $valueRules = match ($field) {
            'commission_override',
            'affiliate_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'active',
            'custom_photos_enabled',
            'add_to_favourites',
            'add_to_default' => ['required', 'boolean'],

            'disabled_variant_gids' => ['array'],

            default => ['present'], // unknown field — allowlist below will reject the request first
        };

        $rules = [
            'product_gid' => ['required', 'string', 'regex:#^gid://shopify/Product/\d+$#'],
            'field' => ['required', 'string', 'in:active,commission_override,affiliate_discount_pct,custom_photos_enabled,add_to_favourites,add_to_default,disabled_variant_gids'],
            'value' => $valueRules,
        ];

        if ($field === 'disabled_variant_gids') {
            // Each entry must be a Shopify ProductVariant GID.
            $rules['value.*'] = ['string', 'regex:#^gid://shopify/ProductVariant/\d+$#'];
        }

        return $rules;
    }
}
