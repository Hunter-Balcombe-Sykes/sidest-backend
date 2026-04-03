<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// V2: Creates Side St sales channel publication on the brand's Shopify store. Products must be published to this channel to appear on affiliate storefronts.
class CreateShopifySalesChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    private const PUBLICATIONS_QUERY = <<<'GRAPHQL'
    query publications($first: Int!) {
      publications(first: $first) {
        edges {
          node {
            id
            name
          }
        }
      }
    }
    GRAPHQL;

    private const PUBLICATION_CREATE = <<<'GRAPHQL'
    mutation publicationCreate($input: PublicationCreateInput!) {
      publicationCreate(input: $input) {
        publication {
          id
          name
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

        if ($shopDomain === '' || $accessToken === '') {
            $metadata['sales_channel_state'] = 'failed';
            $integration->provider_metadata = $metadata;
            $integration->save();
            return;
        }

        try {
            // Check if publication already exists
            if ($this->publicationExists($shopDomain, $accessToken, $apiVersion)) {
                $metadata['sales_channel_state'] = 'registered';
                $integration->provider_metadata = $metadata;
                $integration->save();
                return;
            }

            // Create publication
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->timeout($this->timeout)->post(
                "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json",
                [
                    'query' => self::PUBLICATION_CREATE,
                    'variables' => [
                        'input' => [
                            'autoPublish' => false,
                        ],
                    ],
                ]
            );

            $userErrors = $response->json('data.publicationCreate.userErrors', []);
            if (! empty($userErrors)) {
                Log::warning('Shopify publication creation had errors', [
                    'integration_id' => $this->integrationId,
                    'errors' => $userErrors,
                ]);
            }

            $metadata['sales_channel_state'] = 'registered';

            Log::info('Shopify sales channel publication created', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create Shopify sales channel publication', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            $metadata['sales_channel_state'] = 'failed';
        }

        $integration->provider_metadata = $metadata;
        $integration->save();
    }

    private function publicationExists(string $shopDomain, string $accessToken, string $apiVersion): bool
    {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post(
            "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json",
            [
                'query' => self::PUBLICATIONS_QUERY,
                'variables' => ['first' => 50],
            ]
        );

        $edges = $response->json('data.publications.edges', []);

        // The app's own publication is automatically named after the app
        foreach ($edges as $edge) {
            $name = strtolower(trim((string) Arr::get($edge, 'node.name', '')));
            if (str_contains($name, 'side st') || str_contains($name, 'sidest')) {
                return true;
            }
        }

        return false;
    }
}
