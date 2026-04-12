<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// V2: Brand Design API response. Each token exposes the currently-resolved value
// with provenance so the Design tab can render "from Shopify" vs "overridden" badges.
class BrandDesignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tokens = is_array($this->resource['tokens'] ?? null) ? $this->resource['tokens'] : [];

        return [
            'tokens' => $tokens,
            'synced_at' => $this->resource['synced_at'] ?? null,
            'storefront_url' => $this->resource['storefront_url'] ?? null,
        ];
    }
}
