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

class CreateStorefrontAccessTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

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

    private const STOREFRONT_TOKENS_QUERY = <<<'GRAPHQL'
    query {
      storefrontAccessTokens(first: 10) {
        edges {
          node {
            id
            title
            accessToken
          }
        }
      }
    }
    GRAPHQL;

    public function __construct(public string $integrationId) {
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

        if ($shopDomain === '' || $accessToken === '') {
            return;
        }

        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));

        try {
            // Check if we already have a Side St token
            $existing = $this->findExistingToken($shopDomain, $accessToken, $apiVersion);
            if ($existing) {
                $metadata['storefront_access_token'] = $existing;
                $integration->provider_metadata = $metadata;
                $integration->save();
                return;
            }

            // Create a new one
            $token = $this->createToken($shopDomain, $accessToken, $apiVersion);
            $metadata['storefront_access_token'] = $token;
            $integration->provider_metadata = $metadata;
            $integration->save();

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
        }
    }

    private function findExistingToken(string $shopDomain, string $accessToken, string $apiVersion): ?string
    {
        $data = $this->queryShopify($shopDomain, $accessToken, $apiVersion, self::STOREFRONT_TOKENS_QUERY);
        $edges = Arr::get($data, 'storefrontAccessTokens.edges', []);

        foreach ($edges as $edge) {
            $title = (string) Arr::get($edge, 'node.title', '');
            if ($title === 'Side St') {
                return (string) Arr::get($edge, 'node.accessToken', '');
            }
        }

        return null;
    }

    private function createToken(string $shopDomain, string $accessToken, string $apiVersion): string
    {
        $data = $this->queryShopify($shopDomain, $accessToken, $apiVersion, self::STOREFRONT_TOKEN_CREATE, [
            'input' => ['title' => 'Side St'],
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
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $query,
                'variables' => $variables,
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException("Shopify GraphQL request failed (HTTP {$response->status()}).");
        }

        $payload = $response->json() ?? [];
        $errors = Arr::get($payload, 'errors', []);
        if (is_array($errors) && $errors !== []) {
            throw new \RuntimeException((string) Arr::get($errors, '0.message', 'Shopify GraphQL error.'));
        }

        return is_array(Arr::get($payload, 'data')) ? $payload['data'] : [];
    }
}
