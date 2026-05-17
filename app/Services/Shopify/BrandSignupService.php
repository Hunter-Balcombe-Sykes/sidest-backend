<?php

namespace App\Services\Shopify;

use App\Exceptions\Shopify\InvalidShopDomainException;
use App\Exceptions\Shopify\ShopifyTransportException;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Jobs\Shopify\CreateShopifyMetafieldsJob;
use App\Jobs\Shopify\CreateShopifySalesChannelJob;
use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Services\Professional\BrandStatusService;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

// V2: Handles Shopify reinstalls. Fresh installs (including ones whose Shopify shop email matches an
// existing Partna account) are deferred to the setup wizard via the setup-token + bootstrap flow.
class BrandSignupService
{
    use NormalizesShopDomain;

    public function __construct(
        private readonly ShopProfileAutoFillService $autoFill,
        private readonly ShopifyAdminClient $shopifyClient,
    ) {}

    public function handleReinstall(
        ProfessionalIntegration $integration,
        string $accessToken,
        array $shopData,
        array $scopes,
    ): BrandSignupResult {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        // Revoke the old storefront token at Shopify before issuing a new one.
        // A leaked storefront token otherwise survives indefinitely after reinstall.
        if ($integration->storefront_token !== null) {
            $this->revokeStorefrontToken($metadata, (string) $integration->access_token);
            $integration->update(['storefront_token' => null]);
        }

        // Clear the JSONB reason tag — the brand is reconnecting. The
        // disconnected_at column itself is reset to NULL on the update below
        // (post-DATA-2 these are real columns, not JSONB keys).
        unset($metadata['disconnected_reason']);

        $integration->update([
            'access_token' => $accessToken,
            'provider_metadata' => array_merge($metadata, [
                'scopes' => $scopes,
                'connected_at' => now()->toIso8601String(),
            ]),
            'disconnected_at' => null,
            'webhook_registration_state' => 'queued',
        ]);

        $this->dispatchInstallJobs((string) $integration->id);

        $professional = Professional::findOrFail($integration->professional_id);
        $site = Site::where('professional_id', $professional->id)->firstOrFail();
        $brandProfile = BrandProfile::where('professional_id', $professional->id)->first();

        // Re-evaluate brand status after reinstall. If the brand was disconnected
        // and Shopify-side resources survived, the status service will jump them
        // to the appropriate stage instead of starting from onboarding.
        app(BrandStatusService::class)->sync($professional);

        Log::info('Shopify brand reinstall', [
            'professional_id' => (string) $professional->id,
            'shop_domain' => Arr::get($metadata, 'shop_domain'),
        ]);

        return new BrandSignupResult(
            professional: $professional,
            site: $site,
            brandProfile: $brandProfile,
            integration: $integration,
            isReinstall: true,
        );
    }

    // Path B (auto-link via shopData.email match) was removed for SEC-B#3 / SEC-F#1
    // along with the controller code that called handleExistingBrandConnect.
    // Email-match installs now flow through the setup-token path (Path C) and the
    // integration is attached to a Professional inside BootstrapController, AFTER
    // Supabase JWT auth proves account ownership.

    /**
     * Delete this app's storefront access token from Shopify.
     * Best-effort: any failure is logged and swallowed so the reinstall continues.
     */
    private function revokeStorefrontToken(array $metadata, string $oldAccessToken): void
    {
        $rawShopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        if ($rawShopDomain === '' || $oldAccessToken === '') {
            return;
        }

        try {
            $shop = ShopDomain::fromUntrusted($rawShopDomain);
        } catch (InvalidShopDomainException $e) {
            // Best-effort: a malformed shop_domain in stored metadata is unexpected
            // but not worth aborting reinstall over.
            Log::warning('Shopify reinstall: stored shop_domain failed validation, skipping storefront token revocation', [
                'shop_domain' => $rawShopDomain,
            ]);

            return;
        }

        $shopDomain = $shop->value;
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        try {
            $response = $this->shopifyClient->rest(
                method: 'GET',
                shop: $shop,
                accessToken: $oldAccessToken,
                path: "/admin/api/{$apiVersion}/storefront_access_tokens.json",
                timeoutSeconds: 15,
            );
        } catch (ShopifyTransportException $e) {
            Log::warning('Shopify reinstall: could not list storefront tokens for revocation', [
                'shop_domain' => $shopDomain,
                'status' => $e->status,
            ]);

            return;
        }

        $tokens = $response->json('storefront_access_tokens', []);
        if (! is_array($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            $title = (string) ($token['title'] ?? '');
            if ($title !== 'Partna' && $title !== 'Partna Hydrogen') {
                continue;
            }

            $id = (string) ($token['id'] ?? '');
            if ($id === '') {
                continue;
            }

            try {
                $this->shopifyClient->rest(
                    method: 'DELETE',
                    shop: $shop,
                    accessToken: $oldAccessToken,
                    path: "/admin/api/{$apiVersion}/storefront_access_tokens/{$id}.json",
                    timeoutSeconds: 15,
                );
            } catch (ShopifyTransportException $e) {
                // 404 = already gone, any other status = best-effort failure — both are acceptable.
                Log::warning('Shopify reinstall: storefront token revocation failed', [
                    'shop_domain' => $shopDomain,
                    'token_id' => $id,
                    'status' => $e->status,
                ]);
            }
        }
    }

    public function dispatchInstallJobs(string $integrationId): void
    {
        $jobs = [
            RegisterShopifyWebhooksJob::class,
            CreateStorefrontAccessTokenJob::class,
            CreateShopifyMetafieldsJob::class,
            CreateShopifySalesChannelJob::class,
            // Unified brand-design sync: logos, colours, enums, slogan in one job.
            SyncShopifyBrandDesignJob::class,
        ];

        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch($integrationId);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch Shopify install job', [
                    'integration_id' => $integrationId,
                    'job' => class_basename($jobClass),
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
