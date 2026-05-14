<?php

namespace App\Console\Commands;

use App\Jobs\Shopify\CreateShopifyMetafieldsJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use App\Services\Shopify\ShopDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * One-time migration: copies sidest.* Shopify metafield values to partna.* and
 * creates partna.* definitions on each brand's store.
 *
 * Safe to re-run — only copies where partna.* value is absent.
 *
 * Deployment order:
 *   1. Deploy Comet-Backend + Partna-Hydrogen with partna.* namespace in code.
 *   2. php artisan partna:migrate-metafield-namespace --dry-run
 *   3. php artisan partna:migrate-metafield-namespace
 *   4. Verify a few brands, then: php artisan partna:migrate-metafield-namespace --delete-old
 *   5. Deploy Partna-Shopify-App function change (shopify app deploy) — do this AFTER
 *      step 4 so the function reads partna.affiliate_discount_pct which now has data.
 */
class MigrateMetafieldNamespaceCommand extends Command
{
    protected $signature = 'partna:migrate-metafield-namespace
        {--brand= : Only process this integration_id (UUID). Omit for all connected brands.}
        {--dry-run : Preview what would be written without hitting Shopify.}
        {--delete-old : After copying values, delete the sidest.* definitions (use after verifying).}';

    protected $description = 'Copy sidest.* Shopify metafield values to partna.* for all connected brands.';

    /** Product metafields to migrate: key => Shopify type */
    private const PRODUCT_KEYS = [
        'active' => 'boolean',
        'commission_override' => 'number_decimal',
        'affiliate_discount_pct' => 'number_decimal',
        'custom_photos_enabled' => 'boolean',
        'has_enabled_variants' => 'boolean',
    ];

    /** Variant metafields to migrate */
    private const VARIANT_KEYS = [
        'enabled' => 'boolean',
    ];

    /**
     * Shop metafields to migrate. Excludes brand_design / theme_tokens — those
     * are re-written by SyncShopifyBrandDesignJob on next run anyway.
     */
    private const SHOP_KEYS = [
        'setup_complete' => 'boolean',
        'default_commission_rate' => 'number_decimal',
        'active_collection_handle' => 'single_line_text_field',
        'default_collection_handle' => 'single_line_text_field',
        'favourites_collection_handle' => 'single_line_text_field',
        'high_commission_collection_handle' => 'single_line_text_field',
    ];

    private const PRODUCTS_QUERY = <<<'GRAPHQL'
    query migrateProducts($after: String) {
      products(first: 50, after: $after) {
        edges {
          cursor
          node {
            id
            variants(first: 100) {
              edges {
                node {
                  id
                  sidest_enabled: metafield(namespace: "sidest", key: "enabled") { value }
                  partna_enabled: metafield(namespace: "partna", key: "enabled") { value }
                }
              }
            }
            sidest_active: metafield(namespace: "sidest", key: "active") { value }
            sidest_commission_override: metafield(namespace: "sidest", key: "commission_override") { value }
            sidest_affiliate_discount_pct: metafield(namespace: "sidest", key: "affiliate_discount_pct") { value }
            sidest_custom_photos_enabled: metafield(namespace: "sidest", key: "custom_photos_enabled") { value }
            sidest_has_enabled_variants: metafield(namespace: "sidest", key: "has_enabled_variants") { value }
            partna_active: metafield(namespace: "partna", key: "active") { value }
            partna_commission_override: metafield(namespace: "partna", key: "commission_override") { value }
            partna_affiliate_discount_pct: metafield(namespace: "partna", key: "affiliate_discount_pct") { value }
            partna_custom_photos_enabled: metafield(namespace: "partna", key: "custom_photos_enabled") { value }
            partna_has_enabled_variants: metafield(namespace: "partna", key: "has_enabled_variants") { value }
          }
        }
        pageInfo { hasNextPage }
      }
    }
    GRAPHQL;

    private const SHOP_QUERY = <<<'GRAPHQL'
    query {
      shop {
        id
        sidest_setup_complete: metafield(namespace: "sidest", key: "setup_complete") { value }
        sidest_default_commission_rate: metafield(namespace: "sidest", key: "default_commission_rate") { value }
        sidest_active_collection_handle: metafield(namespace: "sidest", key: "active_collection_handle") { value }
        sidest_default_collection_handle: metafield(namespace: "sidest", key: "default_collection_handle") { value }
        sidest_favourites_collection_handle: metafield(namespace: "sidest", key: "favourites_collection_handle") { value }
        sidest_high_commission_collection_handle: metafield(namespace: "sidest", key: "high_commission_collection_handle") { value }
        partna_setup_complete: metafield(namespace: "partna", key: "setup_complete") { value }
        partna_default_commission_rate: metafield(namespace: "partna", key: "default_commission_rate") { value }
        partna_active_collection_handle: metafield(namespace: "partna", key: "active_collection_handle") { value }
        partna_default_collection_handle: metafield(namespace: "partna", key: "default_collection_handle") { value }
        partna_favourites_collection_handle: metafield(namespace: "partna", key: "favourites_collection_handle") { value }
        partna_high_commission_collection_handle: metafield(namespace: "partna", key: "high_commission_collection_handle") { value }
      }
    }
    GRAPHQL;

    private const METAFIELDS_SET = <<<'GRAPHQL'
    mutation metafieldsSet($metafields: [MetafieldsSetInput!]!) {
      metafieldsSet(metafields: $metafields) {
        metafields { namespace key }
        userErrors { field message }
      }
    }
    GRAPHQL;

    private const DEFINITIONS_QUERY = <<<'GRAPHQL'
    query metafieldDefinitions($ownerType: MetafieldOwnerType!, $namespace: String!, $first: Int!) {
      metafieldDefinitions(ownerType: $ownerType, namespace: $namespace, first: $first) {
        edges {
          node { id key }
        }
      }
    }
    GRAPHQL;

    private const DEFINITION_DELETE = <<<'GRAPHQL'
    mutation metafieldDefinitionDelete($id: ID!, $deleteAllAssociatedMetafields: Boolean!) {
      metafieldDefinitionDelete(id: $id, deleteAllAssociatedMetafields: $deleteAllAssociatedMetafields) {
        deletedDefinitionId
        userErrors { field message }
      }
    }
    GRAPHQL;

    public function handle(ShopifyAdminClient $client): int
    {
        $brandFilter = (string) $this->option('brand');
        $dryRun = (bool) $this->option('dry-run');
        $deleteOld = (bool) $this->option('delete-old');
        $apiVersion = (string) config('services.shopify.api_version', '2025-01');

        $query = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY);

        if ($brandFilter !== '') {
            $query->where('id', $brandFilter);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->warn('No Shopify integrations matched. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d brand(s)%s%s.',
            $integrations->count(),
            $dryRun ? ' [DRY RUN]' : '',
            $deleteOld ? ' [DELETE OLD]' : ''
        ));

        $totalWrites = 0;
        $totalDeleted = 0;
        $failures = 0;

        foreach ($integrations as $integration) {
            $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
            $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
            $accessToken = trim((string) $integration->access_token);

            if ($shopDomain === '' || $accessToken === '') {
                $this->warn("  Skipping {$integration->id}: missing credentials.");
                $failures++;

                continue;
            }

            $this->line("Brand {$integration->professional_id} ({$shopDomain})");

            try {
                // Ensure partna.* definitions exist before writing values.
                if (! $dryRun) {
                    CreateShopifyMetafieldsJob::dispatchSync($integration->id);
                }

                [$productWrites, $productFails] = $this->migrateProductMetafields(
                    $client, $shopDomain, $accessToken, $apiVersion, $dryRun
                );
                $totalWrites += $productWrites;
                $failures += $productFails;

                [$shopWrites, $shopFails] = $this->migrateShopMetafields(
                    $client, $shopDomain, $accessToken, $apiVersion, $dryRun
                );
                $totalWrites += $shopWrites;
                $failures += $shopFails;

                $this->line(sprintf(
                    '  Wrote %d metafield(s)%s.',
                    $productWrites + $shopWrites,
                    $dryRun ? ' (dry run)' : ''
                ));

                if ($deleteOld && ! $dryRun) {
                    $deleted = $this->deleteOldDefinitions($client, $shopDomain, $accessToken, $apiVersion);
                    $totalDeleted += $deleted;
                    $this->line("  Deleted {$deleted} sidest.* definition(s).");
                }
            } catch (\Throwable $e) {
                $this->error("  Failed: {$e->getMessage()}");
                $failures++;
                Log::error('partna:migrate-metafield-namespace failed', [
                    'integration_id' => $integration->id,
                    'shop_domain' => $shopDomain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Done. Writes: %d. Definitions deleted: %d. Failures: %d.%s',
            $totalWrites,
            $totalDeleted,
            $failures,
            $dryRun ? ' (DRY RUN — nothing actually written)' : ''
        ));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{int, int} [$writes, $failures] */
    private function migrateProductMetafields(
        ShopifyAdminClient $client,
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
        bool $dryRun,
    ): array {
        $writes = 0;
        $failures = 0;
        $cursor = null;

        do {
            $variables = $cursor !== null ? ['after' => $cursor] : [];
            $response = $client->graphql(ShopDomain::fromUntrusted($shopDomain), $accessToken, $apiVersion, self::PRODUCTS_QUERY, $variables);
            $edges = $response->json('data.products.edges', []);
            $hasNextPage = (bool) $response->json('data.products.pageInfo.hasNextPage');

            if (! is_array($edges) || empty($edges)) {
                break;
            }

            $cursor = Arr::last($edges)['cursor'] ?? null;

            $batch = [];

            foreach ($edges as $edge) {
                $product = $edge['node'] ?? [];
                $productGid = (string) ($product['id'] ?? '');
                if ($productGid === '') {
                    continue;
                }

                // Product-level fields
                foreach (array_keys(self::PRODUCT_KEYS) as $key) {
                    $alias = str_replace('.', '_', $key);
                    $sidestValue = $product["sidest_{$alias}"]['value'] ?? null;
                    $partnaValue = $product["partna_{$alias}"]['value'] ?? null;
                    if ($sidestValue !== null && $partnaValue === null) {
                        $batch[] = [
                            'namespace' => 'partna',
                            'key' => $key,
                            'value' => $sidestValue,
                            'type' => self::PRODUCT_KEYS[$key],
                            'ownerId' => $productGid,
                        ];
                    }
                }

                // Variant-level fields
                foreach (($product['variants']['edges'] ?? []) as $variantEdge) {
                    $variant = $variantEdge['node'] ?? [];
                    $variantGid = (string) ($variant['id'] ?? '');
                    if ($variantGid === '') {
                        continue;
                    }
                    $sidestValue = $variant['sidest_enabled']['value'] ?? null;
                    $partnaValue = $variant['partna_enabled']['value'] ?? null;
                    if ($sidestValue !== null && $partnaValue === null) {
                        $batch[] = [
                            'namespace' => 'partna',
                            'key' => 'enabled',
                            'value' => $sidestValue,
                            'type' => 'boolean',
                            'ownerId' => $variantGid,
                        ];
                    }
                }
            }

            if (empty($batch)) {
                continue;
            }

            if ($dryRun) {
                foreach ($batch as $mf) {
                    $this->line(sprintf('  would write partna.%s=%s on %s', $mf['key'], $mf['value'], $mf['ownerId']));
                }
                $writes += count($batch);

                continue;
            }

            // Shopify's metafieldsSet accepts up to 25 per call.
            foreach (array_chunk($batch, 25) as $chunk) {
                $result = $client->graphql(ShopDomain::fromUntrusted($shopDomain), $accessToken, $apiVersion, self::METAFIELDS_SET, ['metafields' => $chunk]);
                $errors = $result->json('data.metafieldsSet.userErrors', []);
                if (! empty($errors)) {
                    $this->error('  metafieldsSet error: '.json_encode($errors));
                    $failures++;
                } else {
                    $writes += count($chunk);
                }
            }
        } while ($hasNextPage && $cursor !== null);

        return [$writes, $failures];
    }

    /** @return array{int, int} [$writes, $failures] */
    private function migrateShopMetafields(
        ShopifyAdminClient $client,
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
        bool $dryRun,
    ): array {
        $response = $client->graphql(ShopDomain::fromUntrusted($shopDomain), $accessToken, $apiVersion, self::SHOP_QUERY);
        $shop = $response->json('data.shop', []);
        $shopGid = (string) ($shop['id'] ?? '');

        if ($shopGid === '') {
            return [0, 1];
        }

        $toWrite = [];
        foreach (array_keys(self::SHOP_KEYS) as $key) {
            $alias = str_replace('.', '_', $key);
            $sidestValue = $shop["sidest_{$alias}"]['value'] ?? null;
            $partnaValue = $shop["partna_{$alias}"]['value'] ?? null;
            if ($sidestValue !== null && $partnaValue === null) {
                $toWrite[] = [
                    'namespace' => 'partna',
                    'key' => $key,
                    'value' => $sidestValue,
                    'type' => self::SHOP_KEYS[$key],
                    'ownerId' => $shopGid,
                ];
            }
        }

        if (empty($toWrite)) {
            return [0, 0];
        }

        if ($dryRun) {
            foreach ($toWrite as $mf) {
                $this->line(sprintf('  would write partna.%s (shop)', $mf['key']));
            }

            return [count($toWrite), 0];
        }

        $result = $client->graphql(ShopDomain::fromUntrusted($shopDomain), $accessToken, $apiVersion, self::METAFIELDS_SET, ['metafields' => $toWrite]);
        $errors = $result->json('data.metafieldsSet.userErrors', []);
        if (! empty($errors)) {
            $this->error('  Shop metafields error: '.json_encode($errors));

            return [0, 1];
        }

        return [count($toWrite), 0];
    }

    private function deleteOldDefinitions(
        ShopifyAdminClient $client,
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
    ): int {
        $deleted = 0;

        foreach (['PRODUCT', 'PRODUCTVARIANT', 'SHOP'] as $ownerType) {
            $response = $client->graphql(ShopDomain::fromUntrusted($shopDomain), $accessToken, $apiVersion, self::DEFINITIONS_QUERY, [
                'ownerType' => $ownerType,
                'namespace' => 'sidest',
                'first' => 50,
            ]);

            $edges = $response->json('data.metafieldDefinitions.edges', []);
            if (! is_array($edges)) {
                continue;
            }

            foreach ($edges as $edge) {
                $id = (string) Arr::get($edge, 'node.id', '');
                if ($id === '') {
                    continue;
                }

                // deleteAllAssociatedMetafields: false — preserve the raw values
                // even after the definition is gone. They'll be orphaned but harmless.
                $client->graphql(ShopDomain::fromUntrusted($shopDomain), $accessToken, $apiVersion, self::DEFINITION_DELETE, [
                    'id' => $id,
                    'deleteAllAssociatedMetafields' => false,
                ]);

                $deleted++;
            }
        }

        return $deleted;
    }
}
