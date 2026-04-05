<?php

namespace App\Http\Controllers\Api\Professional\ShopifyIntegration;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesShopDomain;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Jobs\Shopify\CreateShopifyMetafieldsJob;
use App\Jobs\Shopify\CreateShopifySalesChannelJob;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Jobs\Shopify\SyncShopifyBrandLogoJob;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Services\Shopify\ShopProfileAutoFillService;
use App\Services\Store\BrandAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// V2: Core Shopify integration — connects brand's store, registers order webhooks, creates Storefront API tokens for Hydrogen.
class ShopifyIntegrationController extends ApiController
{
    use NormalizesShopDomain, ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandAccessService $brandAccess,
    ) {}

    private function currentShopifyIntegrationForBrand(string $brandProfessionalId): ?ProfessionalIntegration
    {
        return ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();
    }

    /**
     * @return array{0: string, 1: JsonResponse|null}
     */
    private function resolveTargetBrandProfessionalId(
        Request $request,
        ?string $requestedBrandProfessionalId,
        bool $requireForNonBrand
    ): array {
        $professional = $this->currentProfessional($request);
        $requestedBrandProfessionalId = trim((string) $requestedBrandProfessionalId);

        if ($requestedBrandProfessionalId === '') {
            if ($this->brandAccess->isBrandProfessional($professional)) {
                $requestedBrandProfessionalId = (string) $professional->id;
            } elseif ($requireForNonBrand) {
                return ['', $this->error('brand_professional_id is required for this account type.', 422)];
            } else {
                return ['', null];
            }
        }

        if (! $this->brandAccess->canManageShopify($professional, $requestedBrandProfessionalId)) {
            return ['', $this->error('You are not permitted to manage Shopify integrations for this brand.', 403)];
        }

        return [$requestedBrandProfessionalId, null];
    }

    private function ensureShopifyConnected(?ProfessionalIntegration $integration): JsonResponse|null
    {
        if (! $integration || empty($integration->access_token)) {
            return $this->error('Shopify account not connected.', 404);
        }

        return null;
    }

    public function status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null,
            false
        );

        if ($error !== null) {
            return $error;
        }

        if ($targetBrandId === '') {
            return $this->success([
                'eligible' => false,
                'connected' => false,
                'brand_professional_id' => null,
                'shop_domain' => null,
                'shop_id' => null,
                'expires_at' => null,
                'webhook_registration_state' => null,
                'webhook_registration_last_attempt_at' => null,
                'webhook_orders_topic' => null,
            ]);
        }

        $integration = $this->currentShopifyIntegrationForBrand($targetBrandId);
        $metadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = $this->normalizeShopDomain((string) Arr::get($metadata, 'shop_domain', ''));
        $connected = $integration !== null
            && ! empty($integration->access_token)
            && $shopDomain !== '';

        return $this->success([
            'eligible' => true,
            'connected' => $connected,
            'brand_professional_id' => $targetBrandId,
            'shop_domain' => $connected ? $shopDomain : null,
            'shop_id' => $connected ? (string) Arr::get($metadata, 'shop_id') : null,
            'expires_at' => $integration?->expires_at?->toIso8601String(),
            'webhook_registration_state' => $connected ? Arr::get($metadata, 'webhook_registration_state') : null,
            'webhook_registration_last_attempt_at' => $connected
                ? Arr::get($metadata, 'webhook_registration_last_attempt_at')
                : null,
            'webhook_orders_topic' => $connected
                ? (string) Arr::get($metadata, 'webhook_orders_topic', config('services.shopify.webhook_orders_topic', 'orders/paid'))
                : null,
        ]);
    }

    public function connect(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
            'shop_domain' => ['required', 'string', 'max:255'],
            'access_token' => ['required', 'string'],
            'refresh_token' => ['sometimes', 'nullable', 'string'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'shop_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'max:120'],
            'webhook_orders_topic' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shop_data' => ['sometimes', 'nullable', 'array'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validated['brand_professional_id']) ? (string) $validated['brand_professional_id'] : null,
            true
        );

        if ($error !== null) {
            return $error;
        }

        $shopDomain = $this->normalizeShopDomain((string) ($validated['shop_domain'] ?? ''));
        if ($shopDomain === '') {
            return $this->error('shop_domain is required.', 422);
        }

        $actorProfessional = $this->currentProfessional($request);

        $conflictingIntegration = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->where('professional_id', '!=', $targetBrandId)
            ->where('shopify_shop_domain', $shopDomain)
            ->exists();

        if ($conflictingIntegration) {
            return $this->error('This Shopify shop domain is already connected to another brand.', 409);
        }

        $existing = $this->currentShopifyIntegrationForBrand($targetBrandId);
        $existingMetadata = is_array($existing?->provider_metadata) ? $existing->provider_metadata : [];

        $metadata = array_merge($existingMetadata, [
            'shop_domain' => $shopDomain,
            'shop_id' => isset($validated['shop_id']) ? trim((string) $validated['shop_id']) : Arr::get($existingMetadata, 'shop_id'),
            'scopes' => array_values(array_unique(array_filter(array_map(
                static fn ($scope): string => trim((string) $scope),
                Arr::wrap($validated['scopes'] ?? Arr::get($existingMetadata, 'scopes', []))
            ), static fn (string $scope): bool => $scope !== ''))),
            'webhook_orders_topic' => trim((string) ($validated['webhook_orders_topic'] ?? Arr::get(
                $existingMetadata,
                'webhook_orders_topic',
                config('services.shopify.webhook_orders_topic', 'orders/paid')
            ))),
            'connected_at' => now()->toIso8601String(),
            'webhook_registration_state' => 'queued',
        ]);

        $integration = ProfessionalIntegration::query()->updateOrCreate(
            [
                'professional_id' => $targetBrandId,
                'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
            ],
            [
                'external_account_id' => $shopDomain,
                'access_token' => (string) $validated['access_token'],
                'refresh_token' => isset($validated['refresh_token'])
                    ? (string) $validated['refresh_token']
                    : ($existing?->refresh_token),
                'expires_at' => $validated['expires_at'] ?? null,
                'last_catalog_sync_error' => null,
                'provider_metadata' => $metadata,
            ]
        );

        // Ensure BrandProfile exists (covers manual-signup brands connecting Shopify later)
        BrandProfile::firstOrCreate(
            ['professional_id' => $targetBrandId],
            ['setup_complete' => false]
        );

        // Auto-fill empty profile fields from Shopify shop data (manual-signup → Shopify connect)
        $shopData = $validated['shop_data'] ?? null;
        if (is_array($shopData) && $shopData !== []) {
            $professional = Professional::find($targetBrandId);
            $site = Site::where('professional_id', $targetBrandId)->first();
            $brandProfile = BrandProfile::where('professional_id', $targetBrandId)->first();

            if ($professional && $site) {
                app(ShopProfileAutoFillService::class)->fillFromShopData($professional, $site, $brandProfile, $shopData, $integration);
            }
        }

        $webhookRegistrationQueued = true;
        $jobs = [
            RegisterShopifyWebhooksJob::class,
            CreateStorefrontAccessTokenJob::class,
            CreateShopifyMetafieldsJob::class, // chains → CreateShopifyCollectionsJob
            CreateShopifySalesChannelJob::class,
            SyncShopifyBrandLogoJob::class,
        ];

        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch((string) $integration->id);
            } catch (\Throwable $e) {
                $webhookRegistrationQueued = false;
                Log::warning('Failed to dispatch Shopify install job', [
                    'actor_professional_id' => (string) $actorProfessional->id,
                    'brand_professional_id' => $targetBrandId,
                    'integration_id' => (string) $integration->id,
                    'job' => class_basename($jobClass),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->success([
            'connected' => true,
            'brand_professional_id' => $targetBrandId,
            'shop_domain' => $shopDomain,
            'shop_id' => Arr::get($metadata, 'shop_id'),
            'expires_at' => $integration->expires_at?->toIso8601String(),
            'webhook_registration_queued' => $webhookRegistrationQueued,
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null,
            true
        );

        if ($error !== null) {
            return $error;
        }

        $actorProfessional = $this->currentProfessional($request);

        ProfessionalIntegration::query()
            ->where('professional_id', $targetBrandId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->delete();

        Log::info('Shopify disconnected', [
            'actor_professional_id' => (string) $actorProfessional->id,
            'brand_professional_id' => $targetBrandId,
        ]);

        return $this->success([
            'connected' => false,
            'brand_professional_id' => $targetBrandId,
        ]);
    }

    public function token(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null,
            true
        );

        if ($error !== null) {
            return $error;
        }

        $integration = $this->currentShopifyIntegrationForBrand($targetBrandId);
        if ($error = $this->ensureShopifyConnected($integration)) {
            return $error;
        }

        $metadata = is_array($integration?->provider_metadata) ? $integration->provider_metadata : [];

        return $this->success([
            'brand_professional_id' => $targetBrandId,
            'connected' => $integration?->access_token !== null,
            'expires_at' => $integration?->expires_at?->toIso8601String(),
            'shop_domain' => $this->normalizeShopDomain((string) Arr::get($metadata, 'shop_domain', '')),
            'shop_id' => Arr::get($metadata, 'shop_id'),
        ]);
    }

    public function registerWebhooks(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        [$targetBrandId, $error] = $this->resolveTargetBrandProfessionalId(
            $request,
            isset($validator->validated()['brand_professional_id']) ? (string) $validator->validated()['brand_professional_id'] : null,
            true
        );

        if ($error !== null) {
            return $error;
        }

        $integration = $this->currentShopifyIntegrationForBrand($targetBrandId);
        if ($error = $this->ensureShopifyConnected($integration)) {
            return $error;
        }

        RegisterShopifyWebhooksJob::dispatch((string) $integration->id);

        return $this->success([
            'queued' => true,
            'integration_id' => (string) $integration->id,
            'brand_professional_id' => $targetBrandId,
        ]);
    }
}
