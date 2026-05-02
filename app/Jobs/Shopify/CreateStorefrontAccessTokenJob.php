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

// V2: Core. Creates Shopify Storefront API token ("Side St Hydrogen") via GraphQL. Required for Hydrogen storefronts to fetch product data. Matches existing "Side St" tokens for backward compat.
class CreateStorefrontAccessTokenJob implements ShouldBeUnique, ShouldQueue
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

    private const STOREFRONT_TOKEN_CREATE = <<<'GRAPHQL'
    mutation storefrontAccessTokenCreate($input: StorefrontAccessTokenInput!) {
      storefrontAccessTokenCreate(input: $input) {
        storefrontAccessToken {
          accessToken
          title
          id
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    // storefrontAccessTokens query was removed from GraphQL Admin API in 2025-01.
    // Use REST API to check for existing tokens.
    private const STOREFRONT_TOKENS_REST_PATH = '/admin/api/%s/storefront_access_tokens.json';

    /** Populated by handle() — not serialized. */
    private ShopifyAdminClient $client;

    public function __construct(public string $integrationId)
    {
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

        if ($shopDomain === '' || $accessToken === '' || ! preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)) {
            return;
        }

        // Token already provisioned — nothing to do.
        if ($integration->storefront_token !== null && trim((string) $integration->storefront_token) !== '') {
            return;
        }

        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        try {
            // Check if a Side St token already exists in Shopify (e.g. from a previous run).
            $existing = $this->findExistingToken($shopDomain, $accessToken, $apiVersion);
            if ($existing) {
                $integration->update(['storefront_token' => $existing]);

                return;
            }

            // Create a new one.
            $token = $this->createToken($shopDomain, $accessToken, $apiVersion);
            $integration->update(['storefront_token' => $token]);

            Log::info('Shopify Storefront API token created.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create Shopify Storefront API token.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Shopify Storefront API token job permanently failed', [
            'integration_id' => $this->integrationId,
            'error' => $e->getMessage(),
        ]);
    }

    private function findExistingToken(string $shopDomain, string $accessToken, string $apiVersion): ?string
    {
        try {
            $response = $this->client->rest(
                method: 'GET',
                shopDomain: $shopDomain,
                accessToken: $accessToken,
                path: sprintf(self::STOREFRONT_TOKENS_REST_PATH, $apiVersion),
            );
        } catch (\App\Exceptions\Shopify\ShopifyTransportException $e) {
            return null;
        }

        $tokens = $response->json('storefront_access_tokens', []);

        foreach ($tokens as $token) {
            $title = (string) ($token['title'] ?? '');
            if ($title === 'Side St' || $title === 'Side St Hydrogen') {
                return (string) ($token['access_token'] ?? '');
            }
        }

        return null;
    }

    private function createToken(string $shopDomain, string $accessToken, string $apiVersion): string
    {
        $data = $this->queryShopify($shopDomain, $accessToken, $apiVersion, self::STOREFRONT_TOKEN_CREATE, [
            'input' => ['title' => 'Side St Hydrogen'],
        ]);

        $userErrors = Arr::get($data, 'storefrontAccessTokenCreate.userErrors', []);
        if (is_array($userErrors) && $userErrors !== []) {
            $message = (string) Arr::get($userErrors, '0.message', 'Unknown error.');
            throw new \RuntimeException("Storefront token creation failed: {$message}");
        }

        $token = Arr::get($data, 'storefrontAccessTokenCreate.storefrontAccessToken.accessToken');
        if (! $token) {
            throw new \RuntimeException('Storefront token created but no token value returned.');
        }

        return (string) $token;
    }

    private function queryShopify(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables = []): array
    {
        $response = $this->client->graphql(
            $shopDomain,
            $accessToken,
            $apiVersion,
            $query,
            $variables,
        );

        return is_array($response->json('data')) ? $response->json('data') : [];
    }
}
