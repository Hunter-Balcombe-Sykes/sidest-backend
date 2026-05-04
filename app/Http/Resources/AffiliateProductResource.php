<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'gid' => $this->resource['gid'] ?? '',
            'title' => $this->resource['title'] ?? '',
            'handle' => $this->resource['handle'] ?? '',
            'description' => (string) ($this->resource['description'] ?? ''),
            'available_for_sale' => (bool) ($this->resource['available_for_sale'] ?? false),
            'featured_image' => $this->resource['featured_image'] ?? null,
            // Product gallery — populated for the detail modal on the
            // affiliate shop page. Empty array when the product has no
            // non-featured images (in which case the modal falls back to
            // featured_image).
            'images' => $this->resource['images'] ?? [],
            'price_range' => $this->resource['price_range'] ?? null,
            'variants' => $this->resource['variants'] ?? [],
            'selected' => (bool) ($this->resource['selected'] ?? false),
            'sort_order' => $this->resource['sort_order'] ?? null,
            // NULL when the selection has no explicit variant subset; populated
            // array when the affiliate has narrowed the storefront. `variants`
            // above is already the post-intersection set, but surfacing the raw
            // pick lets the UI render the variant picker in its current state.
            'selected_variant_gids' => $this->resource['selected_variant_gids'] ?? null,
            'commission_override' => $this->resource['commission_override'] ?? null,
            'affiliate_discount_pct' => $this->resource['affiliate_discount_pct'] ?? null,
            'in_favourites' => (bool) ($this->resource['in_favourites'] ?? false),
            'custom_photos_enabled' => $this->resource['custom_photos_enabled'] ?? null,
        ];
    }
}
