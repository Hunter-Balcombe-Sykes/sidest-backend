<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
