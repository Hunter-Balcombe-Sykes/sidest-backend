<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Store\UpdateBrandDesignOverridesRequest;
use App\Http\Resources\BrandDesignResource;
use App\Jobs\Shopify\SyncShopifyThemeTokensJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shopify\ThemeTokenParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// V2: Brand Design management. Provides the "re-sync from Shopify" trigger, reads the merged
// (theme_tokens + sitepage_overrides) view, and CRUDs individual override tokens.
class BrandDesignController extends ApiController
{
    use ResolveCurrentProfessional;

    public function show(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $integration = $this->brandIntegration($pro->id);

        if (! $integration) {
            return $this->error('Your Shopify store is not connected.', 422);
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $themeTokens = is_array($metadata['theme_tokens'] ?? null) ? $metadata['theme_tokens'] : [];
        $overrides = is_array($metadata['sitepage_overrides'] ?? null) ? $metadata['sitepage_overrides'] : [];

        $tokens = [];
        foreach (ThemeTokenParserService::TOKEN_KEYS as $key) {
            $hasOverride = array_key_exists($key, $overrides) && $overrides[$key] !== null;
            $shopifyValue = $themeTokens[$key] ?? null;
            $value = $hasOverride ? $overrides[$key] : $shopifyValue;

            $tokens[$key] = [
                'value' => $value,
                'shopify_value' => $shopifyValue,
                'source' => $hasOverride ? 'override' : 'shopify',
            ];
        }

        return $this->success(new BrandDesignResource([
            'tokens' => $tokens,
            'synced_at' => $metadata['theme_tokens_synced_at'] ?? null,
            'storefront_url' => $metadata['primary_domain_url'] ?? null,
        ]));
    }

    public function resync(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $integration = $this->brandIntegration($pro->id);

        if (! $integration) {
            return $this->error('Your Shopify store is not connected.', 422);
        }

        SyncShopifyThemeTokensJob::dispatch((string) $integration->id);

        return $this->success([
            'status' => 'queued',
            'message' => 'Theme token resync queued. Values will refresh shortly.',
        ], 202);
    }

    public function updateOverrides(UpdateBrandDesignOverridesRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $integration = $this->brandIntegration($pro->id);

        if (! $integration) {
            return $this->error('Your Shopify store is not connected.', 422);
        }

        $validated = $request->validated();

        // Only accept allowlisted override keys
        $allowedKeys = ThemeTokenParserService::TOKEN_KEYS;
        $incoming = array_intersect_key($validated, array_flip($allowedKeys));

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $overrides = is_array($metadata['sitepage_overrides'] ?? null) ? $metadata['sitepage_overrides'] : [];

        foreach ($incoming as $key => $value) {
            if ($value === null || $value === '') {
                unset($overrides[$key]);
            } else {
                $overrides[$key] = $value;
            }
        }

        $integration->mergeProviderMetadata(['sitepage_overrides' => $overrides]);

        Cache::forget(CacheKeyGenerator::brandDesignConfig((string) $pro->id));

        return $this->show($request);
    }

    public function resetOverride(Request $request, string $token): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        if (! in_array($token, ThemeTokenParserService::TOKEN_KEYS, true)) {
            return $this->error('Unknown design token.', 422);
        }

        $integration = $this->brandIntegration($pro->id);

        if (! $integration) {
            return $this->error('Your Shopify store is not connected.', 422);
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $overrides = is_array($metadata['sitepage_overrides'] ?? null) ? $metadata['sitepage_overrides'] : [];

        if (array_key_exists($token, $overrides)) {
            unset($overrides[$token]);
            $integration->mergeProviderMetadata(['sitepage_overrides' => $overrides]);
            Cache::forget(CacheKeyGenerator::brandDesignConfig((string) $pro->id));
        }

        return $this->show($request);
    }

    private function brandIntegration(string $professionalId): ?ProfessionalIntegration
    {
        return ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();
    }
}
