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

// V2: Core. Registers all Shopify webhooks (functional + GDPR) via GraphQL on install. Idempotent — skips already-registered topics.
class RegisterShopifyWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    private const WEBHOOK_SUBSCRIPTION_CREATE = <<<'GRAPHQL'
    mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
      webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
        webhookSubscription {
          id
          topic
          endpoint {
            ... on WebhookHttpEndpoint {
              callbackUrl
            }
          }
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    private const WEBHOOK_SUBSCRIPTIONS_QUERY = <<<'GRAPHQL'
    query webhookSubscriptions($topic: WebhookSubscriptionTopic!, $first: Int!) {
      webhookSubscriptions(topics: [$topic], first: $first) {
        edges {
          node {
            id
            topic
            endpoint {
              ... on WebhookHttpEndpoint {
                callbackUrl
              }
            }
          }
        }
      }
    }
    GRAPHQL;

    private const WEBHOOKS = [
        'ORDERS_PAID' => '/api/webhooks/shopify/orders-paid',
        'ORDERS_UPDATED' => '/api/webhooks/shopify/orders-updated',
        'APP_UNINSTALLED' => '/api/webhooks/shopify/app-uninstalled',
        'SHOP_UPDATE' => '/api/webhooks/shopify/shop-update',
        'CUSTOMERS_DATA_REQUEST' => '/api/webhooks/shopify/gdpr/customers-data-request',
        'CUSTOMERS_REDACT' => '/api/webhooks/shopify/gdpr/customers-redact',
        'SHOP_REDACT' => '/api/webhooks/shopify/gdpr/shop-redact',
    ];

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

        if ($shopDomain === '' || $accessToken === '') {
            $integration->mergeProviderMetadata([
                'webhooks_state' => 'failed',
                'webhooks_error' => 'Missing shop domain or access token.',
            ]);

            return;
        }

        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));
        $baseUrl = rtrim((string) config('app.url'), '/');
        $results = [];
        $allSucceeded = true;

        foreach (self::WEBHOOKS as $topic => $path) {
            $callbackUrl = $baseUrl.$path;

            try {
                $existing = $this->findExistingWebhook($shopDomain, $accessToken, $apiVersion, $topic, $callbackUrl);

                if ($existing) {
                    $results[$topic] = ['state' => 'registered', 'webhook_id' => $existing, 'existed' => true];

                    continue;
                }

                $webhookId = $this->createWebhook($shopDomain, $accessToken, $apiVersion, $topic, $callbackUrl);
                $results[$topic] = ['state' => 'registered', 'webhook_id' => $webhookId, 'existed' => false];
            } catch (\Throwable $e) {
                Log::error('Failed to register Shopify webhook.', [
                    'integration_id' => $this->integrationId,
                    'shop_domain' => $shopDomain,
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                ]);

                $results[$topic] = ['state' => 'failed', 'error' => $e->getMessage()];
                $allSucceeded = false;
            }
        }

        $integration->mergeProviderMetadata([
            'webhooks_state' => $allSucceeded ? 'registered' : 'partial',
            'webhooks_registered_at' => now()->toIso8601String(),
            'webhooks_results' => $results,
            // Backward-compat keys
            'webhook_registration_state' => $allSucceeded ? 'registered' : 'partial',
            'webhook_registration_last_attempt_at' => now()->toIso8601String(),
        ]);

        Log::info('Shopify webhook registration complete.', [
            'integration_id' => $this->integrationId,
            'shop_domain' => $shopDomain,
            'all_succeeded' => $allSucceeded,
            'topics_registered' => collect($results)->where('state', 'registered')->count(),
            'topics_failed' => collect($results)->where('state', 'failed')->count(),
        ]);
    }

    private function findExistingWebhook(string $shopDomain, string $accessToken, string $apiVersion, string $topic, string $callbackUrl): ?string
    {
        $data = $this->queryShopify($shopDomain, $accessToken, $apiVersion, self::WEBHOOK_SUBSCRIPTIONS_QUERY, [
            'topic' => $topic,
            'first' => 10,
        ]);

        $edges = Arr::get($data, 'webhookSubscriptions.edges', []);
        if (! is_array($edges)) {
            return null;
        }

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }

            $existingUrl = trim((string) Arr::get($node, 'endpoint.callbackUrl', ''));
            if (strcasecmp($existingUrl, $callbackUrl) === 0) {
                return (string) $node['id'];
            }
        }

        return null;
    }

    private function createWebhook(string $shopDomain, string $accessToken, string $apiVersion, string $topic, string $callbackUrl): string
    {
        $data = $this->queryShopify($shopDomain, $accessToken, $apiVersion, self::WEBHOOK_SUBSCRIPTION_CREATE, [
            'topic' => $topic,
            'webhookSubscription' => [
                'callbackUrl' => $callbackUrl,
                'format' => 'JSON',
            ],
        ]);

        $userErrors = Arr::get($data, 'webhookSubscriptionCreate.userErrors', []);
        if (is_array($userErrors) && $userErrors !== []) {
            $message = (string) Arr::get($userErrors, '0.message', 'Unknown webhook creation error.');
            throw new \RuntimeException("Shopify webhook creation failed for {$topic}: {$message}");
        }

        $webhookId = Arr::get($data, 'webhookSubscriptionCreate.webhookSubscription.id');
        if (! $webhookId) {
            throw new \RuntimeException("Shopify webhook was created for {$topic} but no ID was returned.");
        }

        return (string) $webhookId;
    }

    private function queryShopify(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables = []): array
    {
        $endpoint = "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json";

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($endpoint, [
                'query' => $query,
                'variables' => $variables,
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException("Shopify GraphQL request failed (HTTP {$response->status()}).");
        }

        $payload = $response->json() ?? [];
        $errors = Arr::get($payload, 'errors', []);
        if (is_array($errors) && $errors !== []) {
            $message = (string) Arr::get($errors, '0.message', 'Shopify GraphQL returned errors.');
            throw new \RuntimeException($message);
        }

        return is_array(Arr::get($payload, 'data')) ? $payload['data'] : [];
    }
}
