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

/**
 * Installs the Side St Price automatic discount on the brand's Shopify store.
 *
 * The discount is backed by the `sidest-affiliate-discount` Shopify Function
 * bundled in the Side St app extension. Activating it via
 * `discountAutomaticAppCreate` tells Shopify to run our function on every
 * checkout — the function itself gates on the cart attribute
 * `_sidest_affiliate_id` so brand-direct customers never see the discount.
 *
 * Idempotent: queries existing automatic app discounts for one backed by this
 * app's function_id and skips creation if present. Safe to re-run after
 * deploys that include function-template changes.
 *
 * Dispatch order:
 *   ShopifyIntegrationController → CreateShopifyMetafieldsJob
 *     → CreateShopifyCollectionsJob → CreateShopifyAffiliateDiscountJob (this).
 */
class CreateShopifyAffiliateDiscountJob implements ShouldBeUnique, ShouldQueue
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

    // The function's title inside the Shopify app — used both as the discount
    // title and as the label customers see in checkout / receipts. Keep it
    // brand-neutral because it surfaces on every brand's order confirmation.
    private const DISCOUNT_TITLE = 'Side St Price';

    // Matches the extension handle in Sidest-Embedded/extensions/sidest-affiliate-discount/
    // shopify.extension.toml. Shopify exposes this as `shopifyFunctions.edges.node.title`.
    private const FUNCTION_APP_HANDLE = 'sidest-affiliate-discount';

    // Look up the function GID for the connected app. Shopify returns all
    // functions installed on the store by the app; we filter by apiType
    // ("discount") and the extension handle/title to pick ours.
    private const SHOPIFY_FUNCTIONS_QUERY = <<<'GRAPHQL'
    query shopifyFunctions($first: Int!) {
      shopifyFunctions(first: $first) {
        edges {
          node {
            id
            apiType
            title
            app { title }
          }
        }
      }
    }
    GRAPHQL;

    // Query existing automatic app discounts so we can detect a prior install.
    private const AUTOMATIC_APP_DISCOUNTS_QUERY = <<<'GRAPHQL'
    query automaticAppDiscounts($first: Int!) {
      automaticDiscountNodes(first: $first) {
        edges {
          node {
            id
            automaticDiscount {
              ... on DiscountAutomaticApp {
                title
                status
                appDiscountType { functionId title }
              }
            }
          }
        }
      }
    }
    GRAPHQL;

    private const DISCOUNT_AUTOMATIC_APP_CREATE = <<<'GRAPHQL'
    mutation discountAutomaticAppCreate($automaticAppDiscount: DiscountAutomaticAppInput!) {
      discountAutomaticAppCreate(automaticAppDiscount: $automaticAppDiscount) {
        automaticAppDiscount {
          discountId
          title
          status
        }
        userErrors { field message }
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
            $integration->mergeProviderMetadata(['sidest_discount_state' => 'failed']);

            return;
        }

        try {
            $functionId = $this->resolveFunctionId($shopDomain, $accessToken, $apiVersion);
            if ($functionId === null) {
                // Most common reason: the brand is on an older app version that
                // predates the function, or the Shopify app hasn't been upgraded
                // on their store yet. Keep state as 'pending' (not failed) so a
                // retry after deploy succeeds cleanly.
                $integration->mergeProviderMetadata(['sidest_discount_state' => 'pending']);
                Log::info('sidest-affiliate-discount function not found on store — leaving pending for retry', [
                    'integration_id' => $this->integrationId,
                    'shop_domain' => $shopDomain,
                ]);
            } elseif ($this->automaticDiscountAlreadyInstalled($shopDomain, $accessToken, $apiVersion, $functionId)) {
                $integration->mergeProviderMetadata(['sidest_discount_state' => 'registered']);
            } else {
                $this->createAutomaticDiscount($shopDomain, $accessToken, $apiVersion, $functionId);

                $integration->mergeProviderMetadata(['sidest_discount_state' => 'registered']);

                Log::info('Side St Price automatic discount installed', [
                    'integration_id' => $this->integrationId,
                    'shop_domain' => $shopDomain,
                    'function_id' => $functionId,
                ]);
            }

            // Final step of the OAuth install chain: seed has_enabled_variants
            // on every existing product so the Active Products smart collection
            // resolves from the first page load. Idempotent — skips products
            // where the existing value already matches. Dispatched unconditionally
            // because the backfill is independent of the discount status:
            //   - pending discount (function not deployed yet) → backfill still
            //     needs to seed the flag so collections resolve correctly
            //   - registered (new or already installed) → same reason
            BackfillBrandHasEnabledVariantsJob::dispatch($this->integrationId);
        } catch (\Throwable $e) {
            $integration->mergeProviderMetadata(['sidest_discount_state' => 'failed']);

            Log::error('Failed to install Side St Price automatic discount', [
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
        $integration?->mergeProviderMetadata(['sidest_discount_state' => 'failed']);
    }

    /**
     * Resolve the `sidest-affiliate-discount` function's Shopify GID on this
     * store. Returns null when the app version on the store doesn't include
     * the function yet — the caller keeps state as 'pending' for retry.
     */
    private function resolveFunctionId(string $shopDomain, string $accessToken, string $apiVersion): ?string
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::SHOPIFY_FUNCTIONS_QUERY, [
            'first' => 50,
        ]);

        $edges = $response->json('data.shopifyFunctions.edges', []);
        if (! is_array($edges)) {
            return null;
        }

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $apiType = (string) Arr::get($node, 'apiType', '');
            $title = (string) Arr::get($node, 'title', '');

            // Shopify surfaces the extension handle as the function title.
            // Exact match on handle AND apiType=discount avoids collisions if
            // another app ever ships a function with a similar name.
            if ($apiType === 'discount' && $title === self::FUNCTION_APP_HANDLE) {
                return (string) Arr::get($node, 'id', '') ?: null;
            }
        }

        return null;
    }

    /**
     * True when an automatic app discount backed by this function_id already
     * exists — covers re-runs and idempotency across deploys.
     */
    private function automaticDiscountAlreadyInstalled(string $shopDomain, string $accessToken, string $apiVersion, string $functionId): bool
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::AUTOMATIC_APP_DISCOUNTS_QUERY, [
            'first' => 50,
        ]);

        $edges = $response->json('data.automaticDiscountNodes.edges', []);
        if (! is_array($edges)) {
            return false;
        }

        foreach ($edges as $edge) {
            $existingFunctionId = Arr::get($edge, 'node.automaticDiscount.appDiscountType.functionId');
            if ($existingFunctionId === $functionId) {
                return true;
            }
        }

        return false;
    }

    private function createAutomaticDiscount(string $shopDomain, string $accessToken, string $apiVersion, string $functionId): void
    {
        $input = [
            'title' => self::DISCOUNT_TITLE,
            'functionId' => $functionId,
            // Indefinite start — the function itself decides per-cart whether
            // to apply, so there's no reason to schedule this discount.
            'startsAt' => now()->toIso8601String(),
            // No combines-with tweaks: Side St Price combines with nothing
            // else by default, which is the safe behaviour when brands also
            // run their own promotions. Tighten later if brands ask.
            'combinesWith' => [
                'orderDiscounts' => false,
                'productDiscounts' => false,
                'shippingDiscounts' => true,
            ],
        ];

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::DISCOUNT_AUTOMATIC_APP_CREATE, [
            'automaticAppDiscount' => $input,
        ]);

        $userErrors = $response->json('data.discountAutomaticAppCreate.userErrors', []);

        if (! empty($userErrors)) {
            throw new \RuntimeException('discountAutomaticAppCreate failed: '.json_encode($userErrors));
        }
    }

    private function graphql(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables): \Illuminate\Http\Client\Response
    {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout)->post(
            "https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json",
            array_filter([
                'query' => $query,
                'variables' => ! empty($variables) ? $variables : null,
            ])
        );

        if (! $response->successful()) {
            throw new \RuntimeException("Shopify GraphQL request failed (HTTP {$response->status()}).");
        }

        return $response;
    }
}
