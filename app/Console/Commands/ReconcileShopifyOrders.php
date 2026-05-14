<?php

namespace App\Console\Commands;

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\ShopDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

// Phase 3 backstop: cursor-fetches orders updated since the per-integration
// reconciled_through timestamp and dispatches ProcessShopifyOrderWebhookJob for
// any rows that are missing or stale in commerce.orders. The LWW guard in the
// upsert provides idempotency; no unique event-id constraint is needed here.
class ReconcileShopifyOrders extends Command
{
    protected $signature = 'partna:reconcile-shopify-orders
                            {--integration= : Restrict to a single integration UUID (for testing)}
                            {--since= : Override per-integration reconciled_through (ISO 8601)}
                            {--dry-run : Log what would be dispatched without dispatching}';

    protected $description = 'Backstop reconcile of Shopify orders against commerce.orders (Phase 3)';

    public function handle(ShopifyAdminClient $shopifyClient): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $sinceOverride = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;
        $integrationFilter = $this->option('integration');

        $query = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token');

        if ($integrationFilter !== null) {
            $query->where('id', (string) $integrationFilter);
        }

        $integrations = $query->get();

        Log::info('ReconcileShopifyOrders: starting', [
            'integration_count' => $integrations->count(),
            'dry_run' => $isDryRun,
            'since_override' => $sinceOverride?->toIso8601String(),
        ]);

        $this->info("Reconciling {$integrations->count()} Shopify integration(s)"
            .($isDryRun ? ' [dry-run]' : '').'.');

        foreach ($integrations as $integration) {
            try {
                $this->reconcileIntegration($integration, $shopifyClient, $sinceOverride, $isDryRun);
            } catch (\Throwable $e) {
                // One bad integration should not halt the sweep.
                Log::error('ReconcileShopifyOrders: integration failed', [
                    'integration_id' => (string) $integration->id,
                    'professional_id' => (string) $integration->professional_id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Integration {$integration->id} failed: {$e->getMessage()}");
            }
        }

        $this->info('ReconcileShopifyOrders: done.');

        return self::SUCCESS;
    }

    private function reconcileIntegration(
        ProfessionalIntegration $integration,
        ShopifyAdminClient $shopifyClient,
        ?Carbon $sinceOverride,
        bool $isDryRun,
    ): void {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);

        if ($shopDomain === '' || $accessToken === '') {
            Log::warning('ReconcileShopifyOrders: integration has no shop_domain or access_token, skipping', [
                'integration_id' => (string) $integration->id,
            ]);

            return;
        }

        try {
            $shop = ShopDomain::fromUntrusted($shopDomain);
        } catch (\App\Exceptions\Shopify\InvalidShopDomainException $e) {
            Log::warning('ReconcileShopifyOrders: integration has invalid shop_domain, skipping', [
                'integration_id' => (string) $integration->id,
                'shop_domain' => $shopDomain,
            ]);

            return;
        }

        // Determine the since timestamp. Precedence: --since flag > stored reconciled_through > 7 days ago.
        $since = $sinceOverride
            ?? $integration->reconciled_through
            ?? now()->subDays(7);

        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));
        $fetched = 0;
        $dispatched = 0;
        $skipped = 0;
        $pageInfo = null;

        Log::info('ReconcileShopifyOrders: processing integration', [
            'integration_id' => (string) $integration->id,
            'shop_domain' => $shopDomain,
            'since' => $since->toIso8601String(),
        ]);

        // Cursor-paginate through Shopify orders updated since $since.
        do {
            $params = [
                'limit' => 250,
                'updated_at_min' => $since->toIso8601String(),
                'status' => 'any',
            ];

            if ($pageInfo !== null) {
                // Use page_info cursor for subsequent pages (Shopify cursor-based pagination).
                $params = ['limit' => 250, 'page_info' => $pageInfo];
            }

            $path = '/admin/api/'.$apiVersion.'/orders.json?'.http_build_query($params);

            $response = $shopifyClient->rest(
                method: 'GET',
                shop: $shop,
                accessToken: $accessToken,
                path: $path,
            );

            $orders = $response->json('orders', []);
            $fetched += count($orders);

            foreach ($orders as $shopOrder) {
                $shopifyOrderId = (string) Arr::get($shopOrder, 'id', '');
                $shopOrderUpdatedAt = Arr::get($shopOrder, 'updated_at', '');

                if ($shopifyOrderId === '') {
                    continue;
                }

                // Check whether the local row is missing or stale.
                $local = Order::query()
                    ->where('shopify_shop_domain', $shopDomain)
                    ->where('shopify_order_id', $shopifyOrderId)
                    ->first();

                $needsDispatch = $local === null
                    || ($shopOrderUpdatedAt !== '' && $local->shopify_updated_at < Carbon::parse($shopOrderUpdatedAt));

                if (! $needsDispatch) {
                    $skipped++;

                    continue;
                }

                if ($isDryRun) {
                    Log::info('ReconcileShopifyOrders: [dry-run] would dispatch', [
                        'integration_id' => (string) $integration->id,
                        'shopify_order_id' => $shopifyOrderId,
                        'local_exists' => $local !== null,
                    ]);
                    $dispatched++;

                    continue;
                }

                // dispatchSync: sequential within-integration processing is intentional.
                // The LWW guard in the upsert handles idempotency for null event ids.
                ProcessShopifyOrderWebhookJob::dispatchSync(
                    brandProfessionalId: (string) $integration->professional_id,
                    orderPayload: $shopOrder,
                    shopifyEventId: '',
                    source: 'reconciler',
                );

                $dispatched++;
            }

            // Advance cursor via Link header (Shopify cursor-based pagination).
            $pageInfo = $this->extractNextPageInfo($response->header('Link') ?? '');
        } while ($pageInfo !== null);

        Log::info('ReconcileShopifyOrders: integration complete', [
            'integration_id' => (string) $integration->id,
            'shop_domain' => $shopDomain,
            'fetched' => $fetched,
            'dispatched' => $dispatched,
            'skipped' => $skipped,
            'dry_run' => $isDryRun,
        ]);

        $this->line(sprintf(
            '  %s — fetched %d, dispatched %d, skipped %d%s',
            $shopDomain,
            $fetched,
            $dispatched,
            $skipped,
            $isDryRun ? ' [dry-run]' : '',
        ));

        // Advance reconciled_through to now so the next run only fetches newly-updated orders.
        // Skipped when --dry-run so successive dry runs produce the same output.
        if (! $isDryRun) {
            $integration->forceFill(['reconciled_through' => now()])->save();
        }
    }

    /**
     * Parse Shopify's Link header to extract the next-page page_info cursor.
     * Returns null when there is no next page.
     *
     * Example header: <...?page_info=xxx&limit=250>; rel="next"
     */
    private function extractNextPageInfo(string $linkHeader): ?string
    {
        if ($linkHeader === '') {
            return null;
        }

        // Match any link tagged rel="next"
        if (! preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $match)) {
            return null;
        }

        $url = $match[1];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return isset($params['page_info']) && $params['page_info'] !== '' ? (string) $params['page_info'] : null;
    }
}
