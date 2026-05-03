<?php

namespace App\Console\Commands;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-shot backfill: compute and write sidest.has_enabled_variants on every
 * product across every connected brand store.
 *
 * Why we have this:
 *   The Active Products smart collection was upgraded to require both
 *   sidest.active=true AND sidest.has_enabled_variants=true. New writes are
 *   kept in sync by BrandCatalogService::setVariantEnabledStates — but
 *   existing products on already-connected stores have no value for the new
 *   metafield, so they'd silently drop out of the Active collection until a
 *   brand next touched each variant set. This command catches those up.
 *
 * Safe to re-run:
 *   - Computes the value per product from its current variant state, writes
 *     via metafieldsSet, which is idempotent.
 *   - Does not touch sidest.active or variant-level sidest.enabled.
 *
 * Run order:
 *   1. Deploy the migration + metafield definition + smart collection update.
 *   2. Run with --dry-run first to preview.
 *   3. Run for real: sidest:backfill-has-enabled-variants
 *   4. Optional: --brand=<professional_id> to scope to one brand.
 */
class BackfillHasEnabledVariantsCommand extends Command
{
    protected $signature = 'sidest:backfill-has-enabled-variants
        {--brand= : Only process this brand professional_id (UUID). If omitted, all connected Shopify brands are processed.}
        {--dry-run : Log what would be written without hitting Shopify.}';

    protected $description = 'Backfill sidest.has_enabled_variants on existing products so the Active Products smart collection condition resolves.';

    public function handle(BrandCatalogService $catalogService): int
    {
        $brandFilter = (string) $this->option('brand');
        $dryRun = (bool) $this->option('dry-run');

        $query = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY);

        if ($brandFilter !== '') {
            $query->where('professional_id', $brandFilter);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->warn('No Shopify integrations matched. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Backfilling sidest.has_enabled_variants for %d brand(s)%s.',
            $integrations->count(),
            $dryRun ? ' (DRY RUN)' : ''
        ));

        $totalProducts = 0;
        $totalWrites = 0;
        $totalFailures = 0;

        foreach ($integrations as $integration) {
            $brand = Professional::find($integration->professional_id);
            if (! $brand) {
                $this->warn("Skipping integration {$integration->id}: brand professional not found.");

                continue;
            }

            try {
                $catalog = $catalogService->fetchBrandCatalog($brand);
            } catch (\Throwable $e) {
                $this->error("Failed to fetch catalog for brand {$brand->id}: {$e->getMessage()}");
                $totalFailures++;

                continue;
            }

            $this->line(sprintf('Brand %s — %d product(s)', $brand->id, count($catalog)));

            foreach ($catalog as $product) {
                $gid = $product['gid'] ?? '';
                if ($gid === '') {
                    continue;
                }

                $totalProducts++;

                $variants = $product['variants'] ?? [];
                // true when at least one variant is not explicitly disabled, or
                // when the product has no variants at all (single-SKU).
                $hasEnabled = empty($variants) || collect($variants)->contains(
                    fn (array $v) => ($v['enabled'] ?? null) !== false
                );

                $existing = $product['metafields']['has_enabled_variants'] ?? null;
                if ($existing === $hasEnabled) {
                    continue; // No-op — already correct.
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '  would write %s=%s on %s',
                        'sidest.has_enabled_variants',
                        $hasEnabled ? 'true' : 'false',
                        $gid
                    ));
                    $totalWrites++;

                    continue;
                }

                try {
                    $result = $catalogService->writeHasEnabledVariants($integration, $gid, $hasEnabled);
                    if ($result['success']) {
                        $totalWrites++;
                    } else {
                        $totalFailures++;
                        Log::warning('has_enabled_variants backfill write failed', [
                            'brand_id' => (string) $brand->id,
                            'product_gid' => $gid,
                            'userErrors' => $result['userErrors'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    $totalFailures++;
                    Log::error('has_enabled_variants backfill exception', [
                        'brand_id' => (string) $brand->id,
                        'product_gid' => $gid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info(sprintf(
            'Processed %d product(s). Writes: %d. Failures: %d.%s',
            $totalProducts,
            $totalWrites,
            $totalFailures,
            $dryRun ? ' (DRY RUN — nothing actually written)' : ''
        ));

        return $totalFailures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
