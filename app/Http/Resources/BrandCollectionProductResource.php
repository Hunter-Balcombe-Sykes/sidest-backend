<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandCollectionProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'gid' => $this->resource['gid'] ?? '',
            'title' => $this->resource['title'] ?? '',
            'handle' => $this->resource['handle'] ?? '',
            'featured_image' => $this->resource['featured_image'] ?? null,
        ];
    }
}
