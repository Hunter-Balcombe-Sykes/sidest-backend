<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Store\UpdateBrandStoreSettingsRequest;
use App\Http\Resources\BrandStoreSettingsResource;
use App\Models\Core\Site\Site;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class BrandStoreSettingsController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly BrandCatalogService $catalogService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $storeSettings = BrandStoreSettings::where('professional_id', $pro->id)->first();

        $pro->loadMissing('site');
        $site = $pro->site;
        $siteSettings = is_array($site?->settings) ? $site->settings : [];
        $design = is_array($siteSettings['design'] ?? null) ? $siteSettings['design'] : [];

        $metadata = [];
        try {
            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $metadata = $resolved['metadata'];
        } catch (\Throwable $e) {
        }

        return $this->success(new BrandStoreSettingsResource([
            'default_commission_rate' => $storeSettings?->default_commission_rate ?? config('sidest.store.default_commission_rate', 15),
            'payout_hold_days' => $storeSettings?->payout_hold_days,
            'accent_color' => $design['accent_color'] ?? null,
            'theme_variant' => $design['theme_variant'] ?? null,
            'product_image_ratio' => $design['product_image_ratio'] ?? null,
            'custom_photos_enabled' => Arr::get($metadata, 'custom_photos_enabled', true),
            'custom_photo_position' => $design['custom_photo_position'] ?? 'after',
        ]));
    }

    public function update(UpdateBrandStoreSettingsRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $validated = $request->validated();

        // 1. Local DB write for commission rate
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

        // 2. Write visual settings to site.settings.design
        $pro->loadMissing('site');
        $site = $pro->site;

        $designFields = ['accent_color', 'theme_variant', 'product_image_ratio', 'custom_photo_position'];
        $designUpdates = [];
        foreach ($designFields as $field) {
            if (array_key_exists($field, $validated)) {
                $designUpdates[$field] = $validated[$field];
            }
        }

        if (! empty($designUpdates) && $site) {
            $settings = is_array($site->settings) ? $site->settings : [];
            $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];

            foreach ($designUpdates as $key => $value) {
                $design[$key] = $value;
            }

            $settings['design'] = $design;
            $site->settings = $settings;
            $site->save();
        }

        // 3. Shopify metafield sync (accent_color, theme_variant, product_image_ratio still synced to Shopify)
        try {
            $resolved = $this->catalogService->resolveBrandIntegration($pro);
            $integration = $resolved['integration'];

            $shopMetafields = [];
            $metadataUpdates = [];

            if (array_key_exists('default_commission_rate', $validated)) {
                $shopMetafields[] = ['key' => 'default_commission_rate', 'value' => (string) $validated['default_commission_rate'], 'type' => 'number_decimal'];
            }

            if (array_key_exists('accent_color', $validated)) {
                $shopMetafields[] = ['key' => 'accent_color', 'value' => $validated['accent_color'] ?? '', 'type' => 'single_line_text_field'];
            }

            if (array_key_exists('theme_variant', $validated)) {
                $shopMetafields[] = ['key' => 'theme_variant', 'value' => $validated['theme_variant'] ?? '', 'type' => 'single_line_text_field'];
            }

            if (array_key_exists('product_image_ratio', $validated)) {
                $shopMetafields[] = ['key' => 'product_image_ratio', 'value' => $validated['product_image_ratio'] ?? '', 'type' => 'single_line_text_field'];
            }

            // custom_photos_enabled stays in provider_metadata (feature toggle, not design)
            if (array_key_exists('custom_photos_enabled', $validated)) {
                $metadataUpdates['custom_photos_enabled'] = (bool) $validated['custom_photos_enabled'];
            }

            if (! empty($shopMetafields)) {
                $result = $this->catalogService->setShopMetafields($integration, $shopMetafields);

                if (! $result['success']) {
                    $msg = $result['userErrors'][0]['message'] ?? 'Failed to update Shopify settings.';
                    return $this->error($msg, 422);
                }
            }

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
        $freshSiteSettings = is_array($site?->fresh()?->settings) ? $site->fresh()->settings : [];
        $freshDesign = is_array($freshSiteSettings['design'] ?? null) ? $freshSiteSettings['design'] : [];
        $freshMetadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        return $this->success(new BrandStoreSettingsResource([
            'default_commission_rate' => $storeSettings?->default_commission_rate ?? config('sidest.store.default_commission_rate', 15),
            'payout_hold_days' => $storeSettings?->payout_hold_days,
            'accent_color' => $freshDesign['accent_color'] ?? null,
            'theme_variant' => $freshDesign['theme_variant'] ?? null,
            'product_image_ratio' => $freshDesign['product_image_ratio'] ?? null,
            'custom_photos_enabled' => Arr::get($freshMetadata, 'custom_photos_enabled', true),
            'custom_photo_position' => $freshDesign['custom_photo_position'] ?? 'after',
        ]));
    }
}
