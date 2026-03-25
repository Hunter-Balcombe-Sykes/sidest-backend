<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Retail\BrandProduct;
use App\Models\Retail\BrandStoreSettings;

class BrandOnboardingReadinessService
{
    public function getChecklist(Professional $professional): array
    {
        $professionalId = (string) $professional->id;
        $site = $professional->site ?? Site::where('professional_id', $professionalId)->first();
        $siteId = $site ? (string) $site->id : '';

        $checks = [
            $this->checkSiteImages($siteId),
            $this->checkShopifyConnected($professionalId),
            $this->checkActiveProducts($professionalId),
            $this->checkDefaultProducts($professionalId),
            $this->checkStripeConnected($professional),
            $this->checkCheckoutMethod($professionalId),
        ];

        $completedCount = collect($checks)->filter(fn (array $c): bool => $c['complete'])->count();

        return [
            'complete'        => $completedCount === count($checks),
            'completed_count' => $completedCount,
            'total_count'     => count($checks),
            'checks'          => $checks,
        ];
    }

    private function checkSiteImages(string $siteId): array
    {
        $count = $siteId !== ''
            ? SiteMedia::query()
                ->where('site_id', $siteId)
                ->where('pool', SiteMedia::POOL_CONTENT)
                ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count()
            : 0;

        return [
            'key'      => 'site_images',
            'label'    => 'Upload 5 site page image defaults',
            'complete' => $count >= 5,
            'current'  => $count,
            'required' => 5,
        ];
    }

    private function checkShopifyConnected(string $professionalId): array
    {
        $connected = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token')
            ->whereNotNull('external_account_id')
            ->exists();

        return [
            'key'      => 'shopify_connected',
            'label'    => 'Connect Shopify integration',
            'complete' => $connected,
        ];
    }

    private function checkActiveProducts(string $professionalId): array
    {
        $count = BrandProduct::query()
            ->where('brand_professional_id', $professionalId)
            ->where('shopify_status', 'active')
            ->where('is_sync_active', true)
            ->count();

        return [
            'key'      => 'active_products',
            'label'    => 'Select 3+ active products',
            'complete' => $count >= 3,
            'current'  => $count,
            'required' => 3,
        ];
    }

    private function checkDefaultProducts(string $professionalId): array
    {
        $settings = BrandStoreSettings::where('professional_id', $professionalId)->first();
        $count = $settings ? count($settings->default_affiliate_product_ids) : 0;

        return [
            'key'      => 'default_products',
            'label'    => 'Set 3+ default affiliate products',
            'complete' => $count >= 3,
            'current'  => $count,
            'required' => 3,
        ];
    }

    private function checkStripeConnected(Professional $professional): array
    {
        $connected = mb_strtolower(trim((string) $professional->stripe_connect_status)) === 'active';

        return [
            'key'      => 'stripe_connected',
            'label'    => 'Connect Stripe integration',
            'complete' => $connected,
        ];
    }

    private function checkCheckoutMethod(string $professionalId): array
    {
        $settings = BrandStoreSettings::where('professional_id', $professionalId)->first();
        $hasMethod = $settings
            && is_string($settings->checkout_mode)
            && in_array($settings->checkout_mode, ['shopify', 'stripe'], true);

        return [
            'key'      => 'checkout_method',
            'label'    => 'Select a checkout method',
            'complete' => $hasMethod,
        ];
    }
}
