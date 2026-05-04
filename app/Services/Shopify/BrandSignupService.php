<?php

namespace App\Services\Shopify;

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
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\BrandStatusService;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

// V2: Handles Shopify reinstalls and existing-account connects. Fresh installs are deferred to the setup wizard via bootstrap.
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

        // Clear disconnected markers — the brand is reconnecting.
        unset($metadata['disconnected_at'], $metadata['disconnected_reason']);

        $integration->update([
            'access_token' => $accessToken,
            'provider_metadata' => array_merge($metadata, [
                'scopes' => $scopes,
                'connected_at' => now()->toIso8601String(),
                'webhook_registration_state' => 'queued',
            ]),
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

    public function handleExistingBrandConnect(
        Professional $professional,
        string $shopDomain,
        string $accessToken,
        array $shopData,
        array $scopes,
    ): BrandSignupResult {
        $shopId = trim((string) Arr::get($shopData, 'id', ''));
        $shopCurrency = strtoupper(trim((string) Arr::get($shopData, 'currency', '')));

        $integration = ProfessionalIntegration::updateOrCreate(
            [
                'professional_id' => (string) $professional->id,
                'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
            ],
            [
                'external_account_id' => $shopDomain,
                'access_token' => $accessToken,
                'provider_metadata' => [
                    'shop_domain' => $shopDomain,
                    'shop_id' => $shopId !== '' ? "gid://shopify/Shop/{$shopId}" : null,
                    'shop_currency' => $shopCurrency !== '' ? $shopCurrency : null,
                    'scopes' => $scopes,
                    'webhook_orders_topic' => config('services.shopify.webhook_orders_topic', 'orders/paid'),
                    'connected_at' => now()->toIso8601String(),
                    'webhook_registration_state' => 'queued',
                ],
            ]
        );

        BrandProfile::firstOrCreate(
            ['professional_id' => (string) $professional->id],
            ['setup_complete' => false]
        );

        $site = Site::where('professional_id', $professional->id)->first();
        $brandProfile = BrandProfile::where('professional_id', $professional->id)->first();

        if ($site) {
            $this->autoFill->fillFromShopData($professional, $site, $brandProfile, $shopData);
        }

        app(ProfessionalCacheService::class)->invalidateProfessional($professional);

        $this->dispatchInstallJobs((string) $integration->id);

        Log::info('Shopify brand connect (existing account)', [
            'professional_id' => (string) $professional->id,
            'shop_domain' => $shopDomain,
        ]);

        return new BrandSignupResult(
            professional: $professional,
            site: $site,
            brandProfile: $brandProfile,
            integration: $integration,
            isReinstall: false,
        );
    }

    /**
     * Delete this app's storefront access token from Shopify.
     * Best-effort: any failure is logged and swallowed so the reinstall continues.
     */
    private function revokeStorefrontToken(array $metadata, string $oldAccessToken): void
    {
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        if ($shopDomain === '' || $oldAccessToken === '') {
            return;
        }

        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        try {
            $response = $this->shopifyClient->rest(
                method: 'GET',
                shopDomain: $shopDomain,
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
            if ($title !== 'Side St' && $title !== 'Side St Hydrogen') {
                continue;
            }

            $id = (string) ($token['id'] ?? '');
            if ($id === '') {
                continue;
            }

            try {
                $this->shopifyClient->rest(
                    method: 'DELETE',
                    shopDomain: $shopDomain,
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
