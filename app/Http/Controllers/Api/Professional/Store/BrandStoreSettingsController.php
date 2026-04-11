<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Store\UpdateBrandStoreSettingsRequest;
use App\Http\Resources\BrandStoreSettingsResource;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class BrandStoreSettingsController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandCatalogService $catalogService
    ) {}

    /**
     * GET /brand/store-settings
     *
     * Returns the brand's store settings (local DB + Shopify visual settings from provider_metadata).
     */
    public function show(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $storeSettings = BrandStoreSettings::where('professional_id', $pro->id)->first();

        // Visual settings come from provider_metadata (cached there by the update flow)
        $metadata = [];

        try {
            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $metadata = $resolved['metadata'];
        } catch (\Throwable $e) {
            // Integration may not exist yet — return defaults
        }

        return $this->success(new BrandStoreSettingsResource([
            'default_commission_rate' => $storeSettings?->default_commission_rate ?? config('sidest.store.default_commission_rate', 15),
            'payout_hold_days' => $storeSettings?->payout_hold_days,
            'accent_color' => Arr::get($metadata, 'accent_color'),
            'theme_variant' => Arr::get($metadata, 'theme_variant'),
            'product_image_ratio' => Arr::get($metadata, 'product_image_ratio'),
            'custom_photos_enabled' => Arr::get($metadata, 'custom_photos_enabled', true),
            'custom_photo_position' => Arr::get($metadata, 'custom_photo_position', 'after'),
        ]));
    }

    /**
     * PATCH /brand/store-settings
     *
     * Update store settings: dual write to local DB + Shopify shop metafields + provider_metadata.
     */
    public function update(UpdateBrandStoreSettingsRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $validated = $request->validated();

        // 1. Local DB write for commission rate and payout hold days
        $dbFields = [];
        if (array_key_exists('default_commission_rate', $validated)) {
            $dbFields['default_commission_rate'] = $validated['default_commission_rate'];
        }

        if (! empty($dbFields)) {
            BrandStoreSettings::updateOrCreate(
                ['professional_id' => $pro->id],
                $dbFields
            );
        }

        // 2. Shopify metafield write + provider_metadata cache
        try {
            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $integration = $resolved['integration'];

            $shopMetafields = [];
            $metadataUpdates = [];

            if (array_key_exists('default_commission_rate', $validated)) {
                $shopMetafields[] = ['key' => 'default_commission_rate', 'value' => (string) $validated['default_commission_rate'], 'type' => 'number_decimal'];
            }

            if (array_key_exists('accent_color', $validated)) {
                $val = $validated['accent_color'] ?? '';
                $shopMetafields[] = ['key' => 'accent_color', 'value' => $val, 'type' => 'single_line_text_field'];
                $metadataUpdates['accent_color'] = $val;
            }

            if (array_key_exists('theme_variant', $validated)) {
                $val = $validated['theme_variant'] ?? '';
                $shopMetafields[] = ['key' => 'theme_variant', 'value' => $val, 'type' => 'single_line_text_field'];
                $metadataUpdates['theme_variant'] = $val;
            }

            if (array_key_exists('product_image_ratio', $validated)) {
                $val = $validated['product_image_ratio'] ?? '';
                $shopMetafields[] = ['key' => 'product_image_ratio', 'value' => $val, 'type' => 'single_line_text_field'];
                $metadataUpdates['product_image_ratio'] = $val;
            }

            // Custom photo settings (provider_metadata only, not Shopify metafields)
            if (array_key_exists('custom_photos_enabled', $validated)) {
                $metadataUpdates['custom_photos_enabled'] = (bool) $validated['custom_photos_enabled'];
            }
            if (array_key_exists('custom_photo_position', $validated)) {
                $metadataUpdates['custom_photo_position'] = $validated['custom_photo_position'];
            }

            if (! empty($shopMetafields)) {
                $result = $this->catalogService->setShopMetafields($integration, $shopMetafields);

                if (! $result['success']) {
                    $msg = $result['userErrors'][0]['message'] ?? 'Failed to update Shopify settings.';

                    return $this->error($msg, 422);
                }
            }

            // 3. Update provider_metadata so HydrogenBrandConfigController reads them without hitting Shopify
            if (! empty($metadataUpdates)) {
                $integration->mergeProviderMetadata($metadataUpdates);
            }
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 502);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }

        // Return fresh state
        $storeSettings = BrandStoreSettings::where('professional_id', $pro->id)->first();
        $freshMetadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        return $this->success(new BrandStoreSettingsResource([
            'default_commission_rate' => $storeSettings?->default_commission_rate ?? config('sidest.store.default_commission_rate', 15),
            'payout_hold_days' => $storeSettings?->payout_hold_days,
            'accent_color' => Arr::get($freshMetadata, 'accent_color'),
            'theme_variant' => Arr::get($freshMetadata, 'theme_variant'),
            'product_image_ratio' => Arr::get($freshMetadata, 'product_image_ratio'),
            'custom_photos_enabled' => Arr::get($freshMetadata, 'custom_photos_enabled', true),
            'custom_photo_position' => Arr::get($freshMetadata, 'custom_photo_position', 'after'),
        ]));
    }
}
