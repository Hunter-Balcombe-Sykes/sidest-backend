<?php

namespace App\Services\Professional\Brand;

use App\Enums\BrandStatus;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;

// V2: Brand activation gate. Evaluates checklist (5+ images, Shopify connected, Stripe connected) and syncs brand_status accordingly.
class BrandOnboardingReadinessService
{
    public function __construct(
        private readonly BrandStatusService $brandStatus,
    ) {}

    public function getChecklist(Professional $professional): array
    {
        $professionalId = (string) $professional->id;
        $site = $professional->site ?? Site::where('professional_id', $professionalId)->first();

        $checks = [
            $this->checkSiteImages($site),
            $this->checkShopifyConnected($professionalId),
            $this->checkStripeConnected($professional),
        ];

        $completedCount = collect($checks)->filter(fn (array $c): bool => $c['complete'])->count();
        $isComplete = $completedCount === count($checks);

        $brandStatus = $this->syncBrandStatus($professional);

        return [
            'complete' => $isComplete,
            'completed_count' => $completedCount,
            'total_count' => count($checks),
            'checks' => $checks,
            'brand_status' => $brandStatus,
        ];
    }

    private function syncBrandStatus(Professional $professional): string
    {
        // Use the shared instance so its shopifyConnectedCache from checkShopifyConnected carries over,
        // avoiding a duplicate EXISTS query on the same request.
        $newStatus = $this->brandStatus->sync($professional);

        return $newStatus ?? BrandProfile::where('professional_id', $professional->id)
            ->value('brand_status') ?? BrandStatus::Onboarding->value;
    }

    private function checkSiteImages(?Site $site): array
    {
        // Matches BrandStatusService::hasMinimumImages — counts the design-pool
        // placeholder images the user uploads via the storefront design tab
        // (label: "Upload 5 site page image defaults"). Must stay in sync.
        $count = $site
            ? SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
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
        return [
            'key' => 'shopify_connected',
            'label' => 'Connect Shopify integration',
            'complete' => $this->brandStatus->hasShopifyConnected($professionalId),
        ];
    }

    private function checkStripeConnected(Professional $professional): array
    {
        return [
            'key' => 'stripe_connected',
            'label' => 'Connect Stripe integration',
            'complete' => $this->brandStatus->hasStripeConnected($professional),
        ];
    }
}
