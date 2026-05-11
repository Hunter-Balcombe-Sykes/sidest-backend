<?php

namespace App\Console\Commands;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\Client\ShopifyAdminClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * One-time reconciliation: update existing brands' Partna smart-collection rules
 * to reference the partna.* MetafieldDefinition GIDs instead of the legacy
 * sidest.* GIDs.
 *
 * Context: CreateShopifyCollectionsJob uses findOrCreate-by-title, so once a
 * brand's collections were created on the sidest.* namespace they keep those
 * rules even after our app started writing values under partna.*. The smart
 * collections continue to populate (because we kept sidest.* values alive
 * during migration) but the moment partna:migrate-metafield-namespace --delete-old
 * runs, they go empty. This command rewires the rules to partna.* so --delete-old
 * is safe.
 *
 * Idempotent — only updates a collection when its current ruleSet doesn't match
 * the desired partna GIDs.
 *
 * Deployment order:
 *   1. partna:migrate-metafield-namespace (already done — copies values)
 *   2. partna:reconcile-smart-collection-rules --dry-run (preview)
 *   3. partna:reconcile-smart-collection-rules (or --integration-id=<uuid> for one)
 *   4. Verify a brand or two via Shopify admin → collections still populated
 *   5. partna:migrate-metafield-namespace --delete-old
 */
class ReconcileSmartCollectionRulesCommand extends Command
{
    protected $signature = 'partna:reconcile-smart-collection-rules
        {--integration-id= : Only process this integration_id (UUID). Omit for all connected brands.}
        {--dry-run : Preview what would change without writing to Shopify.}';

    protected $description = 'Rewire existing brands\' Partna smart-collection rules to reference partna.* metafield definitions.';

    /**
     * Collections to reconcile: title => list of desired rules.
     * Mirrors CreateShopifyCollectionsJob::COLLECTIONS — smart collections only.
     */
    private const COLLECTIONS = [
        'Partna — Active Products' => [
            ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'true', 'metafield_ref' => 'partna.active'],
            ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'true', 'metafield_ref' => 'partna.has_enabled_variants'],
        ],
        'Partna — High Commission Products' => [
            ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'true', 'metafield_ref' => 'partna.active'],
            ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'EQUALS', 'condition' => 'true', 'metafield_ref' => 'partna.has_enabled_variants'],
            ['column' => 'PRODUCT_METAFIELD_DEFINITION', 'relation' => 'GREATER_THAN', 'condition' => '0', 'metafield_ref' => 'partna.commission_override'],
        ],
    ];

    private const COLLECTION_QUERY = <<<'GRAPHQL'
    query findCollection($query: String!) {
      collections(query: $query, first: 1) {
        edges {
          node {
            id
            title
            handle
            ruleSet {
              appliedDisjunctively
              rules {
                column
                relation
                condition
                conditionObject {
                  ... on CollectionRuleMetafieldCondition {
                    metafieldDefinition { id namespace key }
                  }
                }
              }
            }
          }
        }
      }
    }
    GRAPHQL;

    private const METAFIELD_DEFINITION_QUERY = <<<'GRAPHQL'
    query metafieldDefinitions($namespace: String!) {
      metafieldDefinitions(ownerType: PRODUCT, namespace: $namespace, first: 25) {
        edges { node { id namespace key } }
      }
    }
    GRAPHQL;

    private const COLLECTION_UPDATE = <<<'GRAPHQL'
    mutation collectionUpdate($input: CollectionInput!) {
      collectionUpdate(input: $input) {
        collection { id handle title }
        userErrors { field message }
      }
    }
    GRAPHQL;

    /** Per-brand cache: ref ("partna.active") => MetafieldDefinition GID */
    private array $definitionCache = [];

    public function __construct(private readonly ShopifyAdminClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $integrations = $this->resolveIntegrations();

        if ($integrations->isEmpty()) {
            $this->warn('No matching integrations.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf('Processing %d integration(s)%s.', $integrations->count(), $dryRun ? ' [dry-run]' : ''));

        $totalUpdated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($integrations as $integration) {
            $this->definitionCache = [];
            $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
            $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', '')) ?: (string) $integration->shopify_shop_domain;
            $accessToken = trim((string) $integration->access_token);

            if ($shopDomain === '' || $accessToken === '') {
                $this->warn("[{$integration->id}] skipped — missing shop domain or access token");
                $totalSkipped++;

                continue;
            }

            $apiVersion = (string) config('services.shopify.api_version', '2025-01');
            $this->line("[{$integration->id}] {$shopDomain}");

            foreach (self::COLLECTIONS as $title => $desiredRules) {
                try {
                    $result = $this->reconcileCollection($shopDomain, $accessToken, $apiVersion, $title, $desiredRules, $dryRun);

                    match ($result) {
                        'updated' => $totalUpdated++,
                        'skipped' => $totalSkipped++,
                        'missing' => null,
                        default => null,
                    };

                    $this->line("  • {$title}: {$result}");
                } catch (\Throwable $e) {
                    $totalErrors++;
                    $this->error("  • {$title}: failed — {$e->getMessage()}");
                    Log::error('reconcile-smart-collection-rules failed', [
                        'integration_id' => (string) $integration->id,
                        'shop_domain' => $shopDomain,
                        'title' => $title,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Done. updated={$totalUpdated} skipped={$totalSkipped} errors={$totalErrors}");

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ProfessionalIntegration>
     */
    private function resolveIntegrations(): \Illuminate\Database\Eloquent\Collection
    {
        $query = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNotNull('access_token');

        if ($id = $this->option('integration-id')) {
            $query->where('id', $id);
        }

        return $query->get();
    }

    /**
     * Returns 'updated' | 'skipped' | 'missing'.
     */
    private function reconcileCollection(
        string $shopDomain,
        string $accessToken,
        string $apiVersion,
        string $title,
        array $desiredRules,
        bool $dryRun,
    ): string {
        $collection = $this->findCollectionByTitle($shopDomain, $accessToken, $apiVersion, $title);

        if ($collection === null) {
            return 'missing';
        }

        $desiredResolvedRules = [];
        foreach ($desiredRules as $rule) {
            $defGid = $this->resolveDefinitionGid($shopDomain, $accessToken, $apiVersion, $rule['metafield_ref']);
            if ($defGid === null) {
                throw new \RuntimeException("Missing MetafieldDefinition for {$rule['metafield_ref']} — run partna:migrate-metafield-namespace first.");
            }

            $desiredResolvedRules[] = [
                'column' => $rule['column'],
                'relation' => $rule['relation'],
                'condition' => $rule['condition'],
                'conditionObjectId' => $defGid,
            ];
        }

        if ($this->ruleSetMatches($collection['ruleSet'] ?? [], $desiredResolvedRules)) {
            return 'skipped';
        }

        if ($dryRun) {
            return 'updated'; // would-update
        }

        $this->updateCollection($shopDomain, $accessToken, $apiVersion, $collection['id'], $desiredResolvedRules);

        return 'updated';
    }

    /**
     * @return array{id:string, title:string, handle:string, ruleSet:?array}|null
     */
    private function findCollectionByTitle(string $shopDomain, string $accessToken, string $apiVersion, string $title): ?array
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTION_QUERY, [
            'query' => "title:'{$title}'",
        ]);

        $edges = $response->json('data.collections.edges', []);
        if (! is_array($edges) || $edges === []) {
            return null;
        }

        $node = $edges[0]['node'] ?? null;
        if (! is_array($node)) {
            return null;
        }

        return [
            'id' => (string) ($node['id'] ?? ''),
            'title' => (string) ($node['title'] ?? ''),
            'handle' => (string) ($node['handle'] ?? ''),
            'ruleSet' => is_array($node['ruleSet'] ?? null) ? $node['ruleSet'] : null,
        ];
    }

    private function resolveDefinitionGid(string $shopDomain, string $accessToken, string $apiVersion, string $ref): ?string
    {
        if (isset($this->definitionCache[$ref])) {
            return $this->definitionCache[$ref];
        }

        [$namespace] = explode('.', $ref, 2);

        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::METAFIELD_DEFINITION_QUERY, [
            'namespace' => $namespace,
        ]);

        $edges = $response->json('data.metafieldDefinitions.edges', []);
        if (! is_array($edges)) {
            return null;
        }

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $key = ($node['namespace'] ?? '').'.'.($node['key'] ?? '');
            $this->definitionCache[$key] = (string) ($node['id'] ?? '');
        }

        return $this->definitionCache[$ref] ?? null;
    }

    /**
     * Compare current ruleSet (from Shopify, with nested conditionObject.metafieldDefinition.id)
     * against desired ruleSet (flat, with conditionObjectId).
     */
    private function ruleSetMatches(array $currentRuleSet, array $desiredResolvedRules): bool
    {
        $currentRules = Arr::get($currentRuleSet, 'rules', []);
        if (! is_array($currentRules) || count($currentRules) !== count($desiredResolvedRules)) {
            return false;
        }

        $extractCurrent = function (array $rule): array {
            return [
                'column' => $rule['column'] ?? null,
                'relation' => $rule['relation'] ?? null,
                'condition' => $rule['condition'] ?? null,
                'conditionObjectId' => Arr::get($rule, 'conditionObject.metafieldDefinition.id'),
            ];
        };

        $current = collect($currentRules)->map($extractCurrent)->sortBy('conditionObjectId')->values()->all();
        $desired = collect($desiredResolvedRules)->sortBy('conditionObjectId')->values()->all();

        return $current == $desired;
    }

    private function updateCollection(string $shopDomain, string $accessToken, string $apiVersion, string $collectionGid, array $resolvedRules): void
    {
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTION_UPDATE, [
            'input' => [
                'id' => $collectionGid,
                'ruleSet' => [
                    'appliedDisjunctively' => false,
                    'rules' => $resolvedRules,
                ],
            ],
        ]);

        $userErrors = $response->json('data.collectionUpdate.userErrors', []);
        if (is_array($userErrors) && $userErrors !== []) {
            $msg = (string) Arr::get($userErrors, '0.message', 'unknown');
            throw new \RuntimeException("collectionUpdate userErrors: {$msg}");
        }
    }

    private function graphql(string $shopDomain, string $accessToken, string $apiVersion, string $query, array $variables = []): \Illuminate\Http\Client\Response
    {
        return $this->client->graphql($shopDomain, $accessToken, $apiVersion, $query, $variables);
    }
}
