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

// V2: Core. Registers orders/paid webhook via GraphQL. Required for commission recording when orders complete.
class RegisterShopifyOrderWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

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
        $metadata['webhook_registration_last_attempt_at'] = now()->toIso8601String();

        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);

        if ($shopDomain === '' || $accessToken === '') {
            $metadata['webhook_registration_state'] = 'failed';
            $metadata['webhook_registration_error'] = 'Missing shop domain or access token.';
            $integration->provider_metadata = $metadata;
            $integration->save();

            return;
        }

        $callbackUrl = rtrim((string) config('app.url'), '/').'/api/webhooks/shopify/orders';
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));
        $topic = $this->resolveGraphqlTopic();

        try {
            // Check if webhook already exists for this topic
            $existing = $this->findExistingWebhook($shopDomain, $accessToken, $apiVersion, $topic, $callbackUrl);

            if ($existing) {
                Log::info('Shopify order webhook already registered.', [
                    'integration_id' => $this->integrationId,
                    'shop_domain' => $shopDomain,
                    'webhook_id' => $existing,
                ]);

                $metadata['webhook_registration_state'] = 'registered';
                $metadata['webhook_id'] = $existing;
                $integration->provider_metadata = $metadata;
                $integration->save();

                return;
            }

            // Register new webhook
            $webhookId = $this->createWebhook($shopDomain, $accessToken, $apiVersion, $topic, $callbackUrl);

            $metadata['webhook_registration_state'] = 'registered';
            $metadata['webhook_id'] = $webhookId;

            Log::info('Shopify order webhook registered.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'webhook_id' => $webhookId,
                'callback_url' => $callbackUrl,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to register Shopify order webhook.', [
                'integration_id' => $this->integrationId,
                'shop_domain' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            $metadata['webhook_registration_state'] = 'failed';
            $metadata['webhook_registration_error'] = $e->getMessage();
        }

        $integration->provider_metadata = $metadata;
        $integration->save();
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
            throw new \RuntimeException("Shopify webhook creation failed: {$message}");
        }

        $webhookId = Arr::get($data, 'webhookSubscriptionCreate.webhookSubscription.id');
        if (! $webhookId) {
            throw new \RuntimeException('Shopify webhook was created but no ID was returned.');
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

    /**
     * Map the configured webhook topic (e.g. orders/paid) to the Shopify GraphQL enum (ORDERS_PAID).
     */
    private function resolveGraphqlTopic(): string
    {
        $topic = trim((string) config('services.shopify.webhook_orders_topic', 'orders/paid'));

        return strtoupper(str_replace('/', '_', $topic));
    }
}
