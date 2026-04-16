<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Brand-facing product shape returned by /api/brand/catalog. Exposes the FULL Shopify
 * variant list alongside the current sidest.* metafield state — the brand UI uses both
 * to render the catalog editor (e.g. show every variant in a dropdown, tick the ones
 * currently enabled). Affiliates use a different resource that pre-filters variants
 * down to the brand-allowed subset; see AffiliateProductResource.
 *
 * Each metafield value is null when the brand hasn't set it (dynamic default applies).
 * See docs/brand-catalog-v2.md §3 for what each metafield means and its default behaviour.
 */
class BrandCatalogProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'gid' => $this->resource['gid'] ?? '',
            'title' => $this->resource['title'] ?? '',
            'handle' => $this->resource['handle'] ?? '',
            'status' => $this->resource['status'] ?? 'ACTIVE',
            'description' => $this->resource['description'] ?? '',
            'featured_image' => $this->resource['featured_image'] ?? null,
            'images' => $this->resource['images'] ?? [],
            'price_range' => $this->resource['price_range'] ?? null,
            'variants' => $this->resource['variants'] ?? [],
            'metafields' => [
                'active' => $this->resource['metafields']['active'] ?? null,
                'commission_override' => $this->resource['metafields']['commission_override'] ?? null,
                'affiliate_discount_pct' => $this->resource['metafields']['affiliate_discount_pct'] ?? null,
                'custom_photos_enabled' => $this->resource['metafields']['custom_photos_enabled'] ?? null,
            ],
        ];
    }
}
