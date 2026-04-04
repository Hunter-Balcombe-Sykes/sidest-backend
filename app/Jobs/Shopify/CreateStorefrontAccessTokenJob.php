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

// V2: Core. Creates Shopify Storefront API token ("Side St Hydrogen") via GraphQL. Required for Hydrogen storefronts to fetch product data. Matches existing "Side St" tokens for backward compat.
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

    // storefrontAccessTokens query was removed from GraphQL Admin API in 2025-01.
    // Use REST API to check for existing tokens.
    private const STOREFRONT_TOKENS_REST_PATH = '/admin/api/%s/storefront_access_tokens.json';

    public function __construct(public string $integrationId)
    {
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
                $integration->mergeProviderMetadata(['storefront_access_token' => $existing]);

                return;
            }

            // Create a new one
            $token = $this->createToken($shopDomain, $accessToken, $apiVersion);
            $integration->mergeProviderMetadata(['storefront_access_token' => $token]);

            Log::info('Shopify Storefront API token created.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create Shopify Storefront API token.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function findExistingToken(string $shopDomain, string $accessToken, string $apiVersion): ?string
    {
        $url = sprintf("https://{$shopDomain}" . self::STOREFRONT_TOKENS_REST_PATH, $apiVersion);

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->get($url);

        if (! $response->ok()) {
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
        $body = ['query' => $query];
        if (! empty($variables)) {
            $body['variables'] = $variables;
        }

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", $body);

        if (! $response->ok()) {
            $body = $response->body();
            Log::error("Shopify Storefront token HTTP {$response->status()}: {$body}");
            throw new \RuntimeException("Shopify GraphQL request failed (HTTP {$response->status()}).");
        }

        $payload = $response->json() ?? [];
        $errors = Arr::get($payload, 'errors', []);
        if (is_array($errors) && $errors !== []) {
            $errorJson = json_encode($errors);
            Log::error("Shopify Storefront token GraphQL errors: {$errorJson}");
            throw new \RuntimeException((string) Arr::get($errors, '0.message', 'Shopify GraphQL error.'));
        }

        return is_array(Arr::get($payload, 'data')) ? $payload['data'] : [];
    }
}
