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
            'available_for_sale' => (bool) ($this->resource['available_for_sale'] ?? false),
            'featured_image' => $this->resource['featured_image'] ?? null,
            'price_range' => $this->resource['price_range'] ?? null,
            'variants' => $this->resource['variants'] ?? [],
            'selected' => (bool) ($this->resource['selected'] ?? false),
            'sort_order' => $this->resource['sort_order'] ?? null,
        ];
    }
}
