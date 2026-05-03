<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Store\UpdateBrandStoreSettingsRequest;
use App\Http\Resources\BrandStoreSettingsResource;
use App\Models\Core\Site\Site;
use App\Models\Retail\BrandStoreSettings;
use App\Services\Hydrogen\HydrogenDeploymentService;
use App\Services\Store\BrandCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class BrandStoreSettingsController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly BrandCatalogService $catalogService,
        private readonly HydrogenDeploymentService $deployment,
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
            'theme_id' => $storeSettings?->theme_id ?? 1,
            // Token is never returned; only expose whether one has been saved
            'oxygen_token_set' => ! empty($storeSettings?->oxygen_deployment_token),
            'oxygen_storefront_id' => $storeSettings?->oxygen_storefront_id,
            'hydrogen_install_confirmed' => (bool) ($storeSettings?->hydrogen_install_confirmed ?? false),
            'storefront_base_url' => $storeSettings
                ? $storeSettings->storefrontBaseUrl($site?->subdomain ?? '')
                : 'https://' . ($site?->subdomain ?? '') . '.sidest.co',
            'storefront_status' => $storeSettings
                ? $this->checkStorefrontStatus($storeSettings, $site?->subdomain ?? '')
                : 'unreachable',
        ]));
    }

    public function update(UpdateBrandStoreSettingsRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $validated = $request->validated();

        // 1. Local DB write for commission rate, payout hold days, and theme selection
        $dbFields = [];
        if (array_key_exists('default_commission_rate', $validated)) {
            $dbFields['default_commission_rate'] = $validated['default_commission_rate'];
        }
        if (array_key_exists('payout_hold_days', $validated)) {
            $dbFields['payout_hold_days'] = (int) $validated['payout_hold_days'];
        }
        if (array_key_exists('theme_id', $validated)) {
            $dbFields['theme_id'] = (int) $validated['theme_id'];
        }
        if (array_key_exists('oxygen_storefront_id', $validated)) {
            $dbFields['oxygen_storefront_id'] = $validated['oxygen_storefront_id'] ?: null;
        }
        if (array_key_exists('hydrogen_install_confirmed', $validated)) {
            $dbFields['hydrogen_install_confirmed'] = (bool) $validated['hydrogen_install_confirmed'];
        }

        $hasOxygenToken = array_key_exists('oxygen_deployment_token', $validated);

        if (! empty($dbFields) || $hasOxygenToken) {
            $settings = BrandStoreSettings::updateOrCreate(
                ['professional_id' => $pro->id],
                $dbFields
            );
            // Token is not in $fillable — set directly to avoid mass-assignment
            if ($hasOxygenToken) {
                $settings->oxygen_deployment_token = $validated['oxygen_deployment_token'] ?: null;
                $settings->save();

                // Trigger a single-brand Oxygen deployment. Best-effort —
                // failures are logged but don't block the wizard.
                if (! empty($settings->oxygen_deployment_token)) {
                    $this->deployment->dispatchDeployment($pro->id);
                }
            }
        }

        // 2. Write visual settings to site.settings.design
        $pro->loadMissing('site');
        $site = $pro->site;

        // theme_id is also written to site.settings.design.default_theme so the
        // account data pipeline can read it without a separate store-settings fetch.
        $designFields = ['accent_color', 'theme_variant', 'product_image_ratio', 'custom_photo_position'];
        $designUpdates = [];
        foreach ($designFields as $field) {
            if (array_key_exists($field, $validated)) {
                $designUpdates[$field] = $validated[$field];
            }
        }

        // Mirror theme_id into site.settings.design.default_theme for account data pipeline
        if (array_key_exists('theme_id', $validated)) {
            $designUpdates['default_theme'] = (int) $validated['theme_id'];
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

        // 3. Shopify metafield sync — only when a Shopify-backed field is being updated.
        // Oxygen credentials are DB-only, so skip the Shopify block entirely for those patches
        // to avoid requiring an active Shopify integration.
        $shopifyFields = ['default_commission_rate', 'accent_color', 'theme_variant', 'product_image_ratio', 'custom_photos_enabled'];
        $needsShopifySync = (bool) array_intersect(array_keys($validated), $shopifyFields);

        $freshMetadata = [];
        $integration = null;

        if ($needsShopifySync) {
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

                $freshMetadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage(), $e->getCode() ?: 502);
            } catch (\Throwable $e) {
                return $this->error('Unable to reach Shopify. Please try again.', 502);
            }
        }

        // Return fresh state
        $storeSettings = BrandStoreSettings::where('professional_id', $pro->id)->first();
        $freshSiteSettings = is_array($site?->fresh()?->settings) ? $site->fresh()->settings : [];
        $freshDesign = is_array($freshSiteSettings['design'] ?? null) ? $freshSiteSettings['design'] : [];

        // When Shopify sync ran, use fresh metadata from integration; otherwise fall back to stored metadata
        if ($needsShopifySync && $integration) {
            $freshMetadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        } else {
            try {
                $resolved = $this->catalogService->resolveBrandIntegration($pro);
                $freshMetadata = is_array($resolved['integration']->provider_metadata) ? $resolved['integration']->provider_metadata : [];
            } catch (\Throwable) {
                $freshMetadata = [];
            }
        }

        return $this->success(new BrandStoreSettingsResource([
            'default_commission_rate' => $storeSettings?->default_commission_rate ?? config('sidest.store.default_commission_rate', 15),
            'payout_hold_days' => $storeSettings?->payout_hold_days,
            'accent_color' => $freshDesign['accent_color'] ?? null,
            'theme_variant' => $freshDesign['theme_variant'] ?? null,
            'product_image_ratio' => $freshDesign['product_image_ratio'] ?? null,
            'custom_photos_enabled' => Arr::get($freshMetadata, 'custom_photos_enabled', true),
            'custom_photo_position' => $freshDesign['custom_photo_position'] ?? 'after',
            'theme_id' => $storeSettings?->theme_id ?? 1,
            'oxygen_token_set' => ! empty($storeSettings?->oxygen_deployment_token),
            'oxygen_storefront_id' => $storeSettings?->oxygen_storefront_id,
            'storefront_base_url' => $storeSettings
                ? $storeSettings->storefrontBaseUrl($site?->subdomain ?? '')
                : 'https://' . ($site?->subdomain ?? '') . '.sidest.co',
            'storefront_status' => $storeSettings
                ? $this->checkStorefrontStatus($storeSettings, $site?->subdomain ?? '')
                : 'unreachable',
        ]));
    }

    /**
     * Trigger an Oxygen deployment for this brand via GitHub Actions workflow_dispatch.
     *
     * Called from the dashboard wizard "Redeploy" button after the brand completes
     * domain setup (connecting the domain + setting it as primary in Shopify Hydrogen).
     */
    public function deploy(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! $pro->isBrand()) {
            return $this->error('This endpoint is only available for brand accounts.', 403);
        }

        $storeSettings = BrandStoreSettings::where('professional_id', $pro->id)->first();

        if (! $storeSettings || empty($storeSettings->oxygen_deployment_token)) {
            return $this->error('No Oxygen deployment token saved. Please complete Oxygen setup first.', 400);
        }

        $this->deployment->dispatchDeployment($pro->id);

        return $this->success([], 'Deployment triggered. It usually takes 1–2 minutes.');
    }

    /**
     * Check whether the storefront is reachable at its base URL.
     *
     * Makes a lightweight GET with redirects disabled so we can
     * distinguish "Hydrogen is serving" (2xx) from "Shopify is
     * falling through to the primary domain" (3xx redirect).
     *
     * @return 'live'|'redirecting'|'unreachable'
     */
    private function checkStorefrontStatus(BrandStoreSettings $settings, string $subdomain): string
    {
        $url = $settings->storefrontBaseUrl($subdomain);

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            if ($response->successful()) {
                return 'live';
            }

            if ($response->redirect()) {
                return 'redirecting';
            }

            return 'unreachable';
        } catch (\Throwable) {
            return 'unreachable';
        }
    }
}
