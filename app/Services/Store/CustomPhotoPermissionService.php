<?php

namespace App\Services\Store;

use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Arr;

class CustomPhotoPermissionService
{
    public function __construct(
        private readonly BrandCatalogService $catalogService,
    ) {}

    // Simplified 2-tier permission model:
    //   1. Global toggle (provider_metadata.custom_photos_enabled) — master switch
    //   2. Per-product opt-out (Shopify metafield) — when global is ON, individual
    //      products can be disabled by setting the metafield to false.
    public function isAllowed(string $brandProfessionalId, ?string $productGid = null): bool
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        // Tier 1: Global master switch — when OFF, nothing is allowed.
        if ($integration) {
            $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
            if (! Arr::get($metadata, 'custom_photos_enabled', true)) {
                return false;
            }
        }

        // Tier 2: Per-product opt-out — when global is ON, a product can be
        // explicitly disabled via its Shopify metafield. null = no opt-out (enabled).
        if ($productGid !== null && $integration !== null) {
            $productSetting = $this->catalogService->fetchProductCustomPhotosMetafield($integration, $productGid);
            if ($productSetting === false) {
                return false;
            }
        }

        return true;
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
