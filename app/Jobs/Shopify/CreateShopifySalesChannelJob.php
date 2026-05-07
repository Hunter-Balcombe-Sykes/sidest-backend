<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

// V2: Creates Partna sales channel publication on the brand's Shopify store. Products must be published to this channel to appear on affiliate storefronts.
class CreateShopifySalesChannelJob implements ShouldBeUnique, ShouldQueue
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

    /** Populated by handle() — not serialized. */
    private ShopifyAdminClient $client;

    public function __construct(
        public string $integrationId
    ) {
        $this->onQueue('integrations');
    }

    public function handle(ShopifyAdminClient $client): void
    {
        $this->client = $client;
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
            $integration->mergeProviderMetadata(['sales_channel_state' => 'failed']);

            return;
        }

        try {
            // Check if publication already exists
            $existingPublicationId = $this->findExistingPublicationId($shopDomain, $accessToken, $apiVersion);
            if ($existingPublicationId !== null) {
                $integration->mergeProviderMetadata([
                    'sales_channel_state' => 'registered',
                    'publication_id' => $existingPublicationId,
                ]);

                return;
            }

            // Create publication
            $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::PUBLICATION_CREATE, [
                'input' => ['autoPublish' => false],
            ]);

            $userErrors = $response->json('data.publicationCreate.userErrors', []);
            if (! empty($userErrors)) {
                $message = (string) Arr::get($userErrors, '0.message', 'Unknown error.');
                throw new \RuntimeException("Shopify publication creation failed: {$message}");
            }

            $publicationId = $response->json('data.publicationCreate.publication.id');
            if (! $publicationId) {
                throw new \RuntimeException('Publication created but no ID returned.');
            }

            $integration->mergeProviderMetadata([
                'sales_channel_state' => 'registered',
                'publication_id' => (string) $publicationId,
            ]);

            Log::info('Shopify sales channel publication created', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'publication_id' => $publicationId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create Shopify sales channel publication', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $integration = ProfessionalIntegration::find($this->integrationId);
        $integration?->mergeProviderMetadata(['sales_channel_state' => 'failed']);
    }

    private function graphql(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables): \Illuminate\Http\Client\Response
    {
        return $this->client->graphql(
            $shopDomain,
            $accessToken,
            $apiVersion,
            $query,
            $variables,
            $this->timeout,
        );
    }

    private function findExistingPublicationId(string $shopDomain, string $accessToken, string $apiVersion): ?string
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::PUBLICATIONS_QUERY, ['first' => 50]);

        $edges = $response->json('data.publications.edges', []);

        // The app's own publication is automatically named after the app
        foreach ($edges as $edge) {
            $name = strtolower(trim((string) Arr::get($edge, 'node.name', '')));
            if (str_contains($name, 'side st') || str_contains($name, 'sidest')) {
                return (string) Arr::get($edge, 'node.id', '');
            }
        }

        return null;
    }
}
