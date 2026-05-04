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

// V2: Core. Registers all Shopify webhooks (functional + GDPR) via GraphQL on install. Idempotent — skips already-registered topics.
class RegisterShopifyWebhooksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

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

    // GDPR compliance topics (CUSTOMERS_DATA_REQUEST, CUSTOMERS_REDACT, SHOP_REDACT) cannot be
    // registered via the GraphQL API — they are handled via shopify.app.toml compliance_topics.
    private const WEBHOOKS = [
        'ORDERS_PAID' => '/api/webhooks/shopify/orders-paid',
        'ORDERS_UPDATED' => '/api/webhooks/shopify/orders-updated',
        'APP_UNINSTALLED' => '/api/webhooks/shopify/app-uninstalled',
        'SHOP_UPDATE' => '/api/webhooks/shopify/shop-update',
        // Triggers a brand design re-sync whenever the merchant publishes a new theme.
        'THEMES_PUBLISH' => '/api/webhooks/shopify/themes-publish',
    ];

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

        if ($shopDomain === '' || $accessToken === '' || ! preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)) {
            $integration->mergeProviderMetadata([
                'webhooks_state' => 'failed',
                'webhooks_error' => 'Missing or invalid shop domain or access token.',
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
