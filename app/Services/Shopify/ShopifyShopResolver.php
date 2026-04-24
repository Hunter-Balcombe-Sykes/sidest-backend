<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;

// V2: Resolves a Shopify shop_domain to the owning professional_id.
// Single source of truth for all Shopify-keyed operations (GDPR webhooks,
// uninstall cleanup, etc.). Returns null when the integration is already
// gone — callers treat that as a valid skip case (Shopify retries may fire
// after we've torn down the integration ourselves).
class ShopifyShopResolver
{
    public function resolveProfessionalId(string $shopDomain): ?string
    {
        $normalised = mb_strtolower(trim($shopDomain));

        if ($normalised === '') {
            return null;
        }

        $integration = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('shopify_shop_domain', $normalised)
            ->first();

        return $integration?->professional_id;
    }

    public function resolveIntegration(string $shopDomain): ?ProfessionalIntegration
    {
        $normalised = mb_strtolower(trim($shopDomain));

        if ($normalised === '') {
            return null;
        }

        return ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('shopify_shop_domain', $normalised)
            ->first();
    }
}
