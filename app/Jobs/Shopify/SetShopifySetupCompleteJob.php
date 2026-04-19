<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// V2: Sets sidest.setup_complete = true shop metafield when brand completes the setup wizard.
class SetShopifySetupCompleteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    private const METAFIELDS_SET = <<<'GRAPHQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields {
          id
          namespace
          key
          value
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    public function __construct(
        public string $integrationId
    ) {
        $this->onQueue('integrations');
    }

    public function handle(): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('id', $this->integrationId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return;
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        if ($shopDomain === '' || $accessToken === '' || ! preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)) {
            return;
        }

        try {
            // Get shop GID
            $shopGidResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->timeout($this->timeout)->post(
                "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json",
                ['query' => '{ shop { id } }']
            );

            $shopGid = (string) $shopGidResponse->json('data.shop.id', '');
            if ($shopGid === '') {
                return;
            }

            // Set setup_complete metafield
            Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->timeout($this->timeout)->post(
                "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json",
                [
                    'query' => self::METAFIELDS_SET,
                    'variables' => [
                        'metafields' => [
                            [
                                'namespace' => 'sidest',
                                'key' => 'setup_complete',
                                'value' => 'true',
                                'type' => 'boolean',
                                'ownerId' => $shopGid,
                            ],
                        ],
                    ],
                ]
            );

            Log::info('Shopify setup_complete metafield set', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to set Shopify setup_complete metafield', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Shopify setup_complete metafield job permanently failed', [
            'integration_id' => $this->integrationId,
            'error' => $e->getMessage(),
        ]);
    }
}
