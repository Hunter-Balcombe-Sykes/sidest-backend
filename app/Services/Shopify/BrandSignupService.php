<?php

namespace App\Services\Shopify;

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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

// V2: Handles Shopify reinstalls and existing-account connects. Fresh installs are deferred to the setup wizard via bootstrap.
class BrandSignupService
{
    use NormalizesShopDomain;

    public function __construct(
        private readonly ShopProfileAutoFillService $autoFill,
    ) {}

    public function handleReinstall(
        ProfessionalIntegration $integration,
        string $accessToken,
        array $shopData,
        array $scopes,
    ): BrandSignupResult {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

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
