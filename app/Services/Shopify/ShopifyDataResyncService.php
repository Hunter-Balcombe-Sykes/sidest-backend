<?php

namespace App\Services\Shopify;

use App\Jobs\Shopify\SyncShopifyBrandLogoJob;
use App\Jobs\Shopify\SyncShopifyThemeTokensJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: On-demand Shopify data refresh. Pulls fresh shop.json, diff-merges profile/brand fields via
//     ShopProfileAutoFillService::resyncFromShopData (preserving user edits), and re-dispatches logo +
//     theme token sync jobs. Used by the Brand "Resync from Shopify" button in settings.
class ShopifyDataResyncService
{
    public function __construct(
        private readonly ShopProfileAutoFillService $autoFill,
    ) {}

    /**
     * @return array{
     *   fields_updated: string[],
     *   fields_preserved: string[],
     *   jobs_dispatched: string[],
     *   last_resynced_at: string,
     * }
     */
    public function resync(ProfessionalIntegration $integration): array
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);

        if ($shopDomain === '' || $accessToken === '' || ! preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)) {
            throw new RuntimeException('Shopify integration is missing a valid shop domain or access token.');
        }

        // Fetch first. If Shopify errors, we never touch the DB — no partial state.
        $shopData = $this->fetchShopData($shopDomain, $accessToken);

        $diff = $this->autoFill->resyncFromShopData($integration, $shopData);

        // Re-pull logo and design tokens from Shopify. Both jobs are idempotent + queued.
        $integrationId = (string) $integration->id;
        SyncShopifyBrandLogoJob::dispatch($integrationId);
        SyncShopifyThemeTokensJob::dispatch($integrationId);

        // Record the resync timestamp. autoFill->resyncFromShopData already refreshed +
        // saved the integration; we do one more read-modify-write for the timestamp so it
        // lives alongside the new snapshot in provider_metadata.
        $lastResyncedAt = now()->toIso8601String();
        $integration->refresh();
        $freshMetadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $freshMetadata['last_resynced_at'] = $lastResyncedAt;
        $integration->provider_metadata = $freshMetadata;
        $integration->save();

        Log::info('Shopify data resync completed.', [
            'integration_id' => $integrationId,
            'shop_domain' => $shopDomain,
            'updated_count' => count($diff['updated']),
            'preserved_count' => count($diff['preserved']),
        ]);

        return [
            'fields_updated' => $diff['updated'],
            'fields_preserved' => $diff['preserved'],
            'jobs_dispatched' => ['logo', 'theme_tokens'],
            'last_resynced_at' => $lastResyncedAt,
        ];
    }

    /**
     * Fetch the shop.json payload from the Shopify Admin REST API.
     * Thrown RuntimeException is caught by the controller and surfaced as a 502/503.
     */
    private function fetchShopData(string $shopDomain, string $accessToken): array
    {
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));
        $endpoint = "https://{$shopDomain}/admin/api/{$apiVersion}/shop.json";

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->get($endpoint);
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to reach Shopify: '.$e->getMessage(), previous: $e);
        }

        if (! $response->ok()) {
            throw new RuntimeException("Shopify shop.json request failed (HTTP {$response->status()}).");
        }

        $shop = $response->json('shop');

        if (! is_array($shop)) {
            throw new RuntimeException('Shopify returned an empty or malformed shop payload.');
        }

        return $shop;
    }
}
