<?php

namespace App\Services\Professional;

use App\Enums\BrandStatus;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Retail\BrandStoreSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for brand lifecycle status.
 *
 * Evaluates status by composing existing data (wizard progress, storefront
 * reachability, onboarding readiness) without duplicating any check logic.
 * Called from controllers after every mutation that could change status.
 *
 * Stages 1-5 are progressive — brands step through in order:
 *   1. onboarding           — fresh signup, no Shopify connection
 *   2. shopify_linked       — OAuth complete, access_token present
 *   3. shopify_configured   — Hydrogen/Oxygen/Domain configured
 *   4. storefront_live      — storefront HTTP-reachable
 *   5. ready_for_affiliates — full live gate (images + Shopify + Stripe)
 *
 * Out-of-band: disconnected (app uninstalled), systems_down (manual override).
 * systems_down now auto-recovers when gates pass at storefront_live or above.
 */
class BrandStatusService
{
    // Per-instance memoization. determine() / sync() call hasShopifyConnected()
    // up to 3× per evaluation (lines 66, 206 via isWizardComplete, 258 via
    // isOnboardingReady) — cache it so we hit the DB once per professional
    // per service-instance lifetime (instance is request-scoped via app()).
    /** @var array<string, bool> */
    private array $shopifyConnectedCache = [];

    /**
     * Determine the brand status for a professional without writing to DB.
     */
    public function determine(Professional $professional): BrandStatus
    {
        $brandProfile = BrandProfile::where('professional_id', $professional->id)->first();
        $integrationCheck = $this->integrationState($professional->id);

        // ── Disconnected: app uninstalled, token nulled, integration row kept ──
        if ($integrationCheck['has_disconnected_at']) {
            return BrandStatus::Disconnected;
        }

        // Integration row exists but no access_token (embedded connect-code without OAuth yet,
        // or app/uninstalled webhook which sets disconnected_at — caught above)
        if ($integrationCheck['has_integration'] && ! $integrationCheck['has_token']) {
            return BrandStatus::Disconnected;
        }

        // ── No integration at all → fresh signup ──
        if (! $integrationCheck['has_integration']) {
            return BrandStatus::Onboarding;
        }

        // ── Shopify linked at minimum (integration exists + access_token) ──
        $storeSettings = BrandStoreSettings::where('professional_id', $professional->id)->first();
        $site = Site::where('professional_id', $professional->id)->first();
        $subdomain = $site?->subdomain ?? '';

        // Shopify-connected brands bypass Hydrogen/Oxygen wizard gate.
        // They can use affiliate marketing without deploying a storefront.
        $hasShopifyConnected = $this->hasShopifyConnected($professional->id);

        // Wizard gate: all steps complete OR Shopify-connected bypass
        $wizardComplete = $hasShopifyConnected || $this->isWizardComplete($storeSettings, $subdomain, $professional->id);

        if (! $wizardComplete) {
            return BrandStatus::ShopifyLinked;
        }

        // ── Wizard complete. Check storefront reachability ──
        $storefrontReachable = $hasShopifyConnected || $this->isStorefrontReachable($storeSettings, $subdomain);

        if (! $storefrontReachable) {
            return BrandStatus::ShopifyConfigured;
        }

        // ── Storefront reachable. Check affiliate readiness ──
        $onboardingReady = $this->isOnboardingReady($professional, $site);

        if (! $onboardingReady) {
            return BrandStatus::StorefrontLive;
        }

        return BrandStatus::ReadyForAffiliates;
    }

    /**
     * Evaluate and persist the current brand status.
     * Returns the new status if it changed, null if unchanged.
     * systems_down auto-recovers when computed status reaches storefront_live or above.
     */
    public function sync(Professional $professional): ?string
    {
        $computed = $this->determine($professional);

        $existing = BrandProfile::where('professional_id', $professional->id)
            ->first();

        $currentStatusValue = $existing?->brand_status ?? 'onboarding';

        // Auto-recovery from systems_down: allow transition out when computed
        // status is storefront_live or above (platform issue resolved).
        if ($currentStatusValue === BrandStatus::SystemsDown->value) {
            if ($computed->isAtLeast(BrandStatus::StorefrontLive)) {
                // Allow recovery — fall through to normal logic
            } else {
                // Still not ready — keep systems_down
                return null;
            }
        }

        $newStatusValue = $computed->value;

        if ($currentStatusValue === $newStatusValue) {
            return null;
        }

        BrandProfile::updateOrCreate(
            ['professional_id' => $professional->id],
            ['brand_status' => $newStatusValue],
        );

        // Audit trail
        DB::table('core.brand_status_history')->insert([
            'professional_id' => $professional->id,
            'from_status' => $currentStatusValue,
            'to_status' => $newStatusValue,
            'reason' => 'auto',
            'created_at' => now(),
        ]);

        Log::info('BrandStatus: status changed', [
            'professional_id' => $professional->id,
            'from' => $currentStatusValue,
            'to' => $newStatusValue,
            'step' => $computed->stepNumber(),
        ]);

        return $newStatusValue;
    }

    /**
     * Only ready_for_affiliates brands can send new affiliate invites.
     */
    public static function canSendInvites(string $status): bool
    {
        return $status === BrandStatus::ReadyForAffiliates->value;
    }

    /**
     * Storefront rendering is allowed for storefront_live and above.
     */
    public static function isStorefrontReady(string $status): bool
    {
        return in_array($status, [
            BrandStatus::StorefrontLive->value,
            BrandStatus::ReadyForAffiliates->value,
        ], true);
    }

    // ── Integration state (single query, avoids N+1) ───────────────────────

    /**
     * @return array{has_integration: bool, has_token: bool, has_disconnected_at: bool}
     */
    private function integrationState(string $professionalId): array
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return [
                'has_integration' => false,
                'has_token' => false,
                'has_disconnected_at' => false,
            ];
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        return [
            'has_integration' => true,
            'has_token' => ! empty($integration->access_token),
            'has_disconnected_at' => ! empty($metadata['disconnected_at'] ?? null),
        ];
    }

    // ── Private checks ──────────────────────────────────────────────────────

    /**
     * All Shopify wizard steps complete AND storefront is reachable.
     *
     * A fully-connected Shopify integration (access_token + external_account_id)
     * satisfies this gate even without Hydrogen/Oxygen flags — the brand can use
     * affiliate marketing without deploying a storefront.
     */
    private function isWizardComplete(?BrandStoreSettings $settings, string $subdomain, string $professionalId): bool
    {
        if ($this->hasShopifyConnected($professionalId)) {
            return true;
        }

        if (! $settings) {
            return false;
        }

        // All wizard flags must be set
        if (
            ! $settings->hydrogen_install_confirmed ||
            empty($settings->getRawOriginal('oxygen_deployment_token')) ||
            empty($settings->oxygen_storefront_id)
        ) {
            return false;
        }

        // Storefront must actually be reachable (HTTP 2xx, no redirect)
        return $this->isStorefrontReachable($settings, $subdomain);
    }

    /**
     * HTTP-check whether the brand's storefront is serving pages (2xx, no redirect).
     *
     * Cached per URL: reachable=true for 60s (steady state), reachable=false for
     * 15s (so a freshly-deployed storefront flips status quickly). Without the
     * cache this single call dominates p95 on hot endpoints like
     * /internal/embedded/provision-integration that fire on every admin page load.
     */
    private function isStorefrontReachable(?BrandStoreSettings $settings, string $subdomain): bool
    {
        if (! $settings) {
            return false;
        }

        $url = 'https://'.$subdomain.'.'.config('partna.public_domain', 'partna.au');
        $cacheKey = 'brand_status:storefront_reachable:'.sha1($url);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            $reachable = $response->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);

        return $reachable;
    }

    /**
     * Onboarding readiness: 5+ images, Shopify connected, Stripe connected.
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
        if (array_key_exists($professionalId, $this->shopifyConnectedCache)) {
            return $this->shopifyConnectedCache[$professionalId];
        }

        return $this->shopifyConnectedCache[$professionalId] = ProfessionalIntegration::query()
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
