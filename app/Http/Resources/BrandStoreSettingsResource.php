<?php

namespace App\Http\Resources;

use App\Enums\BrandStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandStoreSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'default_commission_rate' => (float) ($this->resource['default_commission_rate'] ?? config('partna.store.default_commission_rate', 15)),
            'payout_hold_days' => $this->resource['payout_hold_days'] ?? null,
            'accent_color' => $this->resource['accent_color'] ?? null,
            'theme_variant' => $this->resource['theme_variant'] ?? null,
            'product_image_ratio' => $this->resource['product_image_ratio'] ?? null,
            'custom_photos_enabled' => $this->resource['custom_photos_enabled'] ?? true,
            'custom_photo_position' => $this->resource['custom_photo_position'] ?? 'after',
            'theme_id' => (int) ($this->resource['theme_id'] ?? 1),
            // Oxygen: token is never returned — only whether one is saved
            'oxygen_token_set' => (bool) ($this->resource['oxygen_token_set'] ?? false),
            'oxygen_storefront_id' => $this->resource['oxygen_storefront_id'] ?? null,
            'hydrogen_install_confirmed' => (bool) ($this->resource['hydrogen_install_confirmed'] ?? false),
            'storefront_status' => $this->resource['storefront_status'] ?? 'unreachable',
            'brand_status' => $this->resource['brand_status'] ?? BrandStatus::Onboarding->value,
        ];
    }
}
