<?php

namespace App\Services\Store;

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Arr;

class CustomPhotoPermissionService
{
    public function isAllowed(string $brandProfessionalId, string $affiliateProfessionalId): bool
    {
        // Level 1: Per-affiliate override (wins if set)
        $link = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->first();

        if (! $link) {
            return false;
        }

        if ($link->custom_photos_enabled !== null) {
            return (bool) $link->custom_photos_enabled;
        }

        // Level 2: Global brand setting
        return $this->getGlobalSetting($brandProfessionalId);
    }

    public function getGlobalSetting(string $brandProfessionalId): bool
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return true;
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        return (bool) Arr::get($metadata, 'custom_photos_enabled', true);
    }

    public function getPhotoPosition(string $brandProfessionalId): string
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return 'after';
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $position = Arr::get($metadata, 'custom_photo_position', 'after');

        return in_array($position, ['before', 'after', 'mixed'], true) ? $position : 'after';
    }
}
