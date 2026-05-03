<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Http\JsonResponse;

class PublicOpenInviteController extends ApiController
{
    public function show(string $handle): JsonResponse
    {
        $handle = strtolower(trim($handle));
        if ($handle === '') {
            return $this->error('Brand not found.', 404);
        }

        $brand = Professional::query()
            ->where('handle_lc', $handle)
            ->where('professional_type', 'brand')
            ->where('status', 'active')
            ->with('brandProfile')
            ->first();

        if (! $brand) {
            return $this->error('Brand not found.', 404);
        }

        $brandStatus = $brand->brandProfile?->brand_status ?? 'systems_down';
        if ($brandStatus === 'systems_down') {
            return $this->error('Brand not found.', 404);
        }

        $brandSite = Site::query()
            ->where('professional_id', $brand->id)
            ->first();
        $siteSettings = is_array($brandSite?->settings ?? null) ? $brandSite->settings : [];
        $designSettings = is_array($siteSettings['design'] ?? null) ? $siteSettings['design'] : [];
        $mediaSettings = is_array($designSettings['media'] ?? null) ? $designSettings['media'] : [];

        return $this->success([
            'brand' => [
                'professional_id' => $brand->id,
                'handle' => $brand->handle,
                'display_name' => $brand->display_name,
                'brand_logo_url' => is_string($mediaSettings['brand_logo_url'] ?? $mediaSettings['brandLogoUrl'] ?? null)
                    ? ($mediaSettings['brand_logo_url'] ?? $mediaSettings['brandLogoUrl'])
                    : null,
                'brand_color' => is_string($designSettings['dark_color'] ?? $designSettings['darkColor'] ?? null)
                    ? ($designSettings['dark_color'] ?? $designSettings['darkColor'])
                    : null,
            ],
        ]);
    }
}
