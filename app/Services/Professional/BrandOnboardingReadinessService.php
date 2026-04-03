<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;

// V2: Brand activation gate. Evaluates checklist (5+ images, Shopify connected, Stripe connected) and syncs brand_status accordingly.
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
            $this->checkStripeConnected($professional),
        ];

        $completedCount = collect($checks)->filter(fn (array $c): bool => $c['complete'])->count();
        $isComplete = $completedCount === count($checks);

        $brandStatus = $this->syncBrandStatus($professional, $isComplete);

        return [
            'complete' => $isComplete,
            'completed_count' => $completedCount,
            'total_count' => count($checks),
            'checks' => $checks,
            'brand_status' => $brandStatus,
        ];
    }

    private function syncBrandStatus(Professional $professional, bool $onboardingComplete): string
    {
        $newStatus = $onboardingComplete ? 'active' : 'deactivated';

        BrandProfile::updateOrCreate(
            ['professional_id' => $professional->id],
            ['brand_status' => $newStatus]
        );

        return $newStatus;
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
            'key' => 'site_images',
            'label' => 'Upload 5 site page image defaults',
            'complete' => $count >= 5,
            'current' => $count,
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
            'key' => 'shopify_connected',
            'label' => 'Connect Shopify integration',
            'complete' => $connected,
        ];
    }

    private function checkStripeConnected(Professional $professional): array
    {
        $connected = mb_strtolower(trim((string) $professional->stripe_connect_status)) === 'active';

        return [
            'key' => 'stripe_connected',
            'label' => 'Connect Stripe integration',
            'complete' => $connected,
        ];
    }
}
