<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Brand Design API response. Mirrors the unified shape stored at
// site.settings.design — colours, three normalised enum buckets
// (corner_radius / border_thickness / section_spacing), logo URLs and the
// brand slogan. `shopify_connected` gates the "Re-sync from Shopify" button.
//
// @response {
//   colors: { background, text, accent, border },          hex|null
//   corner_radius: 'square'|'rounded'|'pill',          (default 'rounded' applied upstream)
//   border_thickness: 'hairline'|'standard'|'bold',    (default 'standard' applied upstream)
//   section_spacing: 'tight'|'default'|'spacious',     (default 'default' applied upstream)
//   logo: { full_url, square_url },                        url|null
//   slogan: string|null,
//   font_family: string,                                    enum slug — always set (default applied upstream)
//   shopify_connected: bool
// }
class BrandDesignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $colors = is_array($this->resource['colors'] ?? null) ? $this->resource['colors'] : [];
        $logo = is_array($this->resource['logo'] ?? null) ? $this->resource['logo'] : [];

        return [
            'colors' => [
                'background' => $colors['background'] ?? null,
                'text' => $colors['text'] ?? null,
                'accent' => $colors['accent'] ?? null,
                'border' => $colors['border'] ?? null,
            ],
            'corner_radius' => $this->resource['corner_radius'] ?? null,
            'border_thickness' => $this->resource['border_thickness'] ?? null,
            'section_spacing' => $this->resource['section_spacing'] ?? null,
            'logo' => [
                'full_url' => $logo['full_url'] ?? null,
                'square_url' => $logo['square_url'] ?? null,
            ],
            'slogan' => $this->resource['slogan'] ?? null,
            'font_family' => $this->resource['font_family'] ?? null,
            'shopify_connected' => (bool) ($this->resource['shopify_connected'] ?? false),
        ];
    }
}
