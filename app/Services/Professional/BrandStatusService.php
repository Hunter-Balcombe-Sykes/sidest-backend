<?php

namespace App\Services\Professional;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for brand lifecycle status.
 *
 * Evaluates status by composing existing data (wizard progress, storefront
 * reachability, onboarding readiness) without duplicating any check logic.
 * Called from controllers after every mutation that could change status.
 *
 * States:
 *   building     — default, getting Shopify/Hydrogen configured
 *   preview      — wizard complete, storefront reachable, brand content not ready
 *   live         — fully ready, can send invites
 *   systems_down — manual override (preserved across evaluations)
 */
class BrandStatusService
{
    /**
     * Determine the brand status for a professional without writing to DB.
     * Returns one of: building, preview, live, systems_down.
     */
    public function determine(Professional $professional): string
    {
        $brandProfile = BrandProfile::where('professional_id', $professional->id)->first();
        $currentStatus = $brandProfile?->brand_status ?? 'building';

        // Manual override — never auto-transition out of systems_down
        if ($currentStatus === 'systems_down') {
            return 'systems_down';
        }

        $storeSettings = BrandStoreSettings::where('professional_id', $professional->id)->first();
        $site = Site::where('professional_id', $professional->id)->first();
        $subdomain = $site?->subdomain ?? '';

        // Preview gate: all wizard steps must be complete + storefront reachable
        $wizardComplete = $this->isWizardComplete($storeSettings, $subdomain);
        if (! $wizardComplete) {
            return 'building';
        }

        // Live gate: onboarding readiness (images, Shopify, Stripe)
        $onboardingReady = $this->isOnboardingReady($professional, $site);
        if ($onboardingReady) {
            return 'live';
        }

        return 'preview';
    }

    /**
     * Evaluate and persist the current brand status.
     * Returns the new status if it changed, null if unchanged.
     */
    public function sync(Professional $professional): ?string
    {
        $newStatus = $this->determine($professional);

        $existing = BrandProfile::where('professional_id', $professional->id)
            ->value('brand_status');

        if ($existing === $newStatus) {
            return null;
        }

        BrandProfile::updateOrCreate(
            ['professional_id' => $professional->id],
            ['brand_status' => $newStatus],
        );

        Log::info('BrandStatus: status changed', [
            'professional_id' => $professional->id,
            'from' => $existing ?? 'null',
            'to' => $newStatus,
        ]);

        return $newStatus;
    }

    /**
     * Only live brands can send new affiliate invites.
     */
    public static function canSendInvites(string $status): bool
    {
        return $status === 'live';
    }

    /**
     * Storefront rendering is allowed for preview and live brands.
     */
    public static function isStorefrontReady(string $status): bool
    {
        return in_array($status, ['preview', 'live'], true);
    }

    // ── Private checks ──────────────────────────────────────────────────────

    /**
     * All Shopify wizard steps complete AND storefront is reachable.
     */
    private function isWizardComplete(?BrandStoreSettings $settings, string $subdomain): bool
    {
        if (! $settings) {
            return false;
        }

        // All four wizard flags must be set
        if (
            ! $settings->hydrogen_install_confirmed ||
            empty($settings->getRawOriginal('oxygen_deployment_token')) ||
            empty($settings->oxygen_storefront_id) ||
            ! $settings->domain_wizard_complete
        ) {
            return false;
        }

        // Storefront must actually be reachable (HTTP 2xx, no redirect)
        return $this->isStorefrontReachable($settings, $subdomain);
    }

    /**
     * HTTP-check whether the brand's storefront is serving pages (2xx, no redirect).
     */
    private function isStorefrontReachable(BrandStoreSettings $settings, string $subdomain): bool
    {
        $url = $settings->storefrontBaseUrl($subdomain);

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Onboarding readiness: 5+ images, Shopify connected, Stripe connected.
     * Mirrors the checks in BrandOnboardingReadinessService without duplicating
     * its getChecklist() method (which also includes label/metadata for UI).
     */
    private function isOnboardingReady(Professional $professional, ?Site $site): bool
    {
        return $this->hasMinimumImages($site)
            && $this->hasShopifyConnected($professional->id)
            && $this->hasStripeConnected($professional);
    }

    private function hasMinimumImages(?Site $site): bool
    {
        if (! $site) {
            return false;
        }

        $count = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_CONTENT)
            ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();

        return $count >= 5;
    }

    private function hasShopifyConnected(string $professionalId): bool
    {
        return ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token')
            ->whereNotNull('external_account_id')
            ->exists();
    }

    private function hasStripeConnected(Professional $professional): bool
    {
        return mb_strtolower(trim((string) $professional->stripe_connect_status)) === 'active';
    }
}
