<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandStoreSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'default_commission_rate' => (float) ($this->resource['default_commission_rate'] ?? config('sidest.store.default_commission_rate', 15)),
            'payout_hold_days' => $this->resource['payout_hold_days'] ?? null,
            'accent_color' => $this->resource['accent_color'] ?? null,
            'theme_variant' => $this->resource['theme_variant'] ?? null,
            'product_image_ratio' => $this->resource['product_image_ratio'] ?? null,
            'custom_photos_enabled' => $this->resource['custom_photos_enabled'] ?? true,
            'custom_photo_position' => $this->resource['custom_photo_position'] ?? 'after',
        ];
    }
}
