<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Brand Design API response. Mirrors the unified shape returned by
// BrandDesignController::show — accent colour, theme_mode, three normalised
// enum buckets (corner_radius / border_thickness / section_spacing), logo
// URLs, slogan, font, the placeholder list, and shopify_connected.
//
// Background / text / border are no longer brand-picked — they're derived from
// theme_mode in each Sidest theme. Only accent stays user-customisable.
//
// @response {
//   colors: { accent },                                hex|null
//   theme_mode: 'light'|'dark',                         (default 'light' applied upstream)
//   corner_radius: 'square'|'default'|'pill',          (default 'default' applied upstream)
//   border_thickness: 'hairline'|'default'|'bold',     (default 'default' applied upstream)
//   section_spacing: 'tight'|'default'|'spacious',     (default 'default' applied upstream)
//   logo: { full_url, square_url },                        url|null
//   slogan: string|null,
//   font_family: string,                                    enum slug — always set (default applied upstream)
//   placeholders: [{ id, alt_text, url, sort_order, processing_state }],
//   shopify_connected: bool
// }
class BrandDesignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $colors = is_array($this->resource['colors'] ?? null) ? $this->resource['colors'] : [];
        $logo = is_array($this->resource['logo'] ?? null) ? $this->resource['logo'] : [];
        $placeholders = is_array($this->resource['placeholders'] ?? null) ? $this->resource['placeholders'] : [];

        return [
            'colors' => [
                'accent' => $colors['accent'] ?? null,
            ],
            'theme_mode' => $this->resource['theme_mode'] ?? null,
            'corner_radius' => $this->resource['corner_radius'] ?? null,
            'border_thickness' => $this->resource['border_thickness'] ?? null,
            'section_spacing' => $this->resource['section_spacing'] ?? null,
            'logo' => [
                'full_url' => $logo['full_url'] ?? null,
                'square_url' => $logo['square_url'] ?? null,
            ],
            'slogan' => $this->resource['slogan'] ?? null,
            'font_family' => $this->resource['font_family'] ?? null,
            'placeholders' => array_map(
                fn (array $p) => [
                    'id' => $p['id'] ?? null,
                    'alt_text' => $p['alt_text'] ?? null,
                    'url' => $p['url'] ?? null,
                    'sort_order' => isset($p['sort_order']) ? (int) $p['sort_order'] : 0,
                    'processing_state' => $p['processing_state'] ?? 'ready',
                ],
                $placeholders
            ),
            'shopify_connected' => (bool) ($this->resource['shopify_connected'] ?? false),
        ];
    }
}
