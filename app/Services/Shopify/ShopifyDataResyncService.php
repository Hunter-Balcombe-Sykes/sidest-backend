<?php

namespace App\Services\Shopify;

use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: On-demand Shopify data refresh. Pulls fresh shop.json, diff-merges profile/brand fields via
//     ShopProfileAutoFillService::resyncFromShopData (preserving user edits), and re-dispatches the
//     unified brand-design sync job. Used by the Brand "Resync from Shopify" button in settings.
class ShopifyDataResyncService
{
    public function __construct(
        private readonly ShopProfileAutoFillService $autoFill,
        private readonly ShopifyAdminClient $client,
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

        // Fetch BEFORE the transaction — Shopify Admin API calls take ~200ms and would hold
        // row locks on professionals / brand_profiles / integrations the whole time if inside.
        $shopData = $this->fetchShopData($shopDomain, $accessToken);

        $integrationId = (string) $integration->id;
        $lastResyncedAt = now()->toIso8601String();

        // Post-API writes go in one transaction so partial failures roll back cleanly:
        //   - diff-merge into Professional / BrandProfile / ProfessionalIntegration
        //   - last_resynced_at stamp on integration metadata
        //   - unified brand-design job dispatch (Laravel queues defer until commit inside txn)
        $diff = DB::connection('pgsql')->transaction(function () use ($integration, $integrationId, $shopData, $lastResyncedAt) {
            $diff = $this->autoFill->resyncFromShopData($integration, $shopData);

            // Atomic merge so sibling keys (webhook_ids, storefront_access_token, etc.) survive.
            $integration->mergeProviderMetadata(['last_resynced_at' => $lastResyncedAt]);

            // Dispatched inside the transaction on purpose — queues hold them until commit,
            // so a rollback prevents an orphaned brand-design job from firing.
            SyncShopifyBrandDesignJob::dispatch($integrationId);

            return $diff;
        });

        Log::info('Shopify data resync completed.', [
            'integration_id' => $integrationId,
            'shop_domain' => $shopDomain,
            'updated_count' => count($diff['updated']),
            'preserved_count' => count($diff['preserved']),
        ]);

        return [
            'fields_updated' => $diff['updated'],
            'fields_preserved' => $diff['preserved'],
            'jobs_dispatched' => ['brand_design'],
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

        try {
            $response = $this->client->rest(
                method: 'GET',
                shop: ShopDomain::fromUntrusted($shopDomain),
                accessToken: $accessToken,
                path: "/admin/api/{$apiVersion}/shop.json",
            );
        } catch (\App\Exceptions\Shopify\ShopifyTransportException $e) {
            throw new RuntimeException('Unable to reach Shopify: '.$e->getMessage(), previous: $e);
        }

        $shop = $response->json('shop');

        if (! is_array($shop)) {
            throw new RuntimeException('Shopify returned an empty or malformed shop payload.');
        }

        return $shop;
    }
}
