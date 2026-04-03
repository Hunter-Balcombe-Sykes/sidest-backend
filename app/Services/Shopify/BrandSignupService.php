<?php

namespace App\Services\Shopify;

use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Jobs\Shopify\CreateShopifyCollectionsJob;
use App\Jobs\Shopify\CreateShopifyMetafieldsJob;
use App\Jobs\Shopify\CreateShopifySalesChannelJob;
use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Jobs\Shopify\SyncShopifyBrandLogoJob;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Services\Auth\SupabaseAdminService;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\AccountTypeDefaultsService;
use App\Services\Professional\SiteProvisioningService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BrandSignupService
{
    use NormalizesShopDomain;

    public function __construct(
        private readonly SupabaseAdminService $supabaseAdmin,
        private readonly ShopProfileAutoFillService $autoFill,
        private readonly AccountTypeDefaultsService $accountDefaults,
        private readonly SiteProvisioningService $siteProvisioning,
    ) {}

    public function handleOAuthCallback(
        string $shopDomain,
        string $accessToken,
        array $shopData,
        array $scopes,
    ): BrandSignupResult {
        $shopDomain = $this->normalizeShopDomain($shopDomain);
        $shopEmail = strtolower(trim((string) Arr::get($shopData, 'email', '')));

        // Step 1: Check reinstall — same shop domain already connected
        $existingIntegration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if ($existingIntegration) {
            return $this->handleReinstall($existingIntegration, $accessToken, $shopData, $scopes);
        }

        // Step 2: Fresh signup
        return $this->handleFreshSignup($shopDomain, $accessToken, $shopEmail, $shopData, $scopes);
    }

    private function handleReinstall(
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

    private function handleFreshSignup(
        string $shopDomain,
        string $accessToken,
        string $shopEmail,
        array $shopData,
        array $scopes,
    ): BrandSignupResult {
        // Create Supabase user (no password — magic link / App Bridge auth)
        $supabaseUser = $this->supabaseAdmin->createUser($shopEmail, [
            'signup_source' => 'shopify_oauth',
            'shop_domain' => $shopDomain,
        ]);

        $authUserId = $supabaseUser['id'];

        $result = DB::transaction(function () use ($shopDomain, $accessToken, $shopEmail, $shopData, $scopes, $authUserId) {
            $shopName = trim((string) Arr::get($shopData, 'name', ''));
            $handle = $this->generateBrandHandle($shopName ?: $shopDomain);

            // Create Professional
            $professional = new Professional([
                'handle' => $handle,
                'display_name' => $shopName ?: $shopDomain,
                'bio' => null,
                'professional_type' => 'brand',
                'status' => 'active',
                'onboarding_step' => 0,
                'qr_slug' => $this->siteProvisioning->generateQrSlug($handle),
                'primary_email' => $shopEmail,
                'first_name' => $shopName ?: '',
                'last_name' => null,
                'phone' => null,
                'public_contact_number' => null,
                'public_contact_email' => null,
                'handle_lc' => strtolower($handle),
                'country_code' => null,
                'timezone' => null,
            ]);
            $professional->auth_user_id = $authUserId;
            $professional->save();

            // Create Site
            $base = $this->siteProvisioning->subdomainBaseFromHandle($handle);
            $site = $this->siteProvisioning->createSiteWithRetry((string) $professional->id, $base);

            // Create BrandProfile
            $brandProfile = BrandProfile::create([
                'professional_id' => (string) $professional->id,
                'setup_complete' => false,
            ]);

            // Apply account-type defaults
            $this->accountDefaults->applyDefaults($professional, $site);

            // Auto-fill profile from Shopify shop data
            $this->autoFill->fillFromShopData($professional, $site, $brandProfile, $shopData);

            // Seed free subscription
            $this->siteProvisioning->ensureFreeSubscription($professional);

            // Welcome notification
            Notification::query()->firstOrCreate(
                [
                    'professional_id' => $professional->id,
                    'type' => 'Info',
                    'title' => 'Welcome to Side St',
                ],
                [
                    'body' => 'Your brand account has been created. Complete the setup wizard to get started.',
                    'cta_url' => null,
                    'severity' => 'info',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]
            );

            // Side St updates subscription
            $this->ensureSidestUpdatesSubscription($shopEmail);

            // Create integration
            $shopId = trim((string) Arr::get($shopData, 'id', ''));
            $shopCurrency = strtoupper(trim((string) Arr::get($shopData, 'currency', '')));
            $integration = ProfessionalIntegration::create([
                'professional_id' => (string) $professional->id,
                'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
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
            ]);

            app(ProfessionalCacheService::class)->invalidateProfessional($professional);

            return new BrandSignupResult(
                professional: $professional,
                site: $site,
                brandProfile: $brandProfile,
                integration: $integration,
                isReinstall: false,
            );
        });

        $this->dispatchInstallJobs((string) $result->integration->id);

        Log::info('Shopify brand fresh signup', [
            'professional_id' => (string) $result->professional->id,
            'shop_domain' => $shopDomain,
            'supabase_user_created' => $supabaseUser['created'],
        ]);

        return $result;
    }

    private function dispatchInstallJobs(string $integrationId): void
    {
        try {
            RegisterShopifyWebhooksJob::dispatch($integrationId);
            CreateStorefrontAccessTokenJob::dispatch($integrationId);
            CreateShopifyMetafieldsJob::dispatch($integrationId);
            CreateShopifyCollectionsJob::dispatch($integrationId);
            CreateShopifySalesChannelJob::dispatch($integrationId);
            SyncShopifyBrandLogoJob::dispatch($integrationId);
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch Shopify install jobs', [
                'integration_id' => $integrationId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function generateBrandHandle(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'brand';
        }

        // Truncate to leave room for potential suffix
        $base = substr($base, 0, 50);

        // Check uniqueness
        if (! Professional::query()->where('handle_lc', strtolower($base))->exists()) {
            return $base;
        }

        // Append numeric suffix
        for ($i = 1; $i <= 20; $i++) {
            $candidate = $base . '-' . $i;
            if (! Professional::query()->where('handle_lc', strtolower($candidate))->exists()) {
                return $candidate;
            }
        }

        // Fallback: random suffix
        return $base . '-' . Str::lower(Str::random(6));
    }

    private function ensureSidestUpdatesSubscription(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') return;

        $listKey = 'sidest_updates';

        $existing = EmailSubscription::query()
            ->whereNull('professional_id')
            ->where('list_key', $listKey)
            ->where('email_lc', $email)
            ->first();

        if ($existing) {
            return;
        }

        $sub = new EmailSubscription([
            'professional_id' => null,
            'list_key' => $listKey,
            'email' => $email,
            'email_lc' => $email,
            'full_name' => null,
            'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
        ]);

        $sub->markSubscribed(['source' => 'shopify_oauth']);
        $sub->save();
    }
}
