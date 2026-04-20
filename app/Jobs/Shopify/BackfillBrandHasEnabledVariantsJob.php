<?php

namespace App\Jobs\Shopify;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Store\BrandCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Computes and writes `sidest.has_enabled_variants` on every product in a
 * brand's Shopify catalog at the end of the OAuth install chain.
 *
 * Why we need this at install time:
 *   The derived flag is only written when brand-side variant enable/disable
 *   actions run (setVariantEnabledStates). On a fresh install no brand
 *   actions have happened yet, so every product starts with an empty value —
 *   which in turn means the Active Products smart collection (which requires
 *   `has_enabled_variants = true`) sees no products as "active" even if
 *   `sidest.active` is true. Running this once post-install seeds the flag
 *   from the current variant state so the collection resolves correctly
 *   from the get-go.
 *
 * Logic (matches BackfillHasEnabledVariantsCommand, scoped to one brand):
 *   hasEnabled = true when the product has no variants at all, OR when at
 *   least one variant has `sidest.enabled != false`. Missing metafield
 *   defaults to enabled.
 *
 * Idempotent: skips products where the current value already matches.
 *
 * Dispatch order:
 *   ShopifyIntegrationController → CreateShopifyMetafieldsJob →
 *     CreateShopifyCollectionsJob →
 *       CreateShopifyAffiliateDiscountJob →
 *         BackfillBrandHasEnabledVariantsJob (this, last in the chain).
 */
class BackfillBrandHasEnabledVariantsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Larger timeout than the sibling install jobs — reading the full catalog
    // can be slow for brands with hundreds of products, and each write is a
    // separate GraphQL call. 120s covers ~500 products comfortably.
    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return $this->integrationId;
    }

    public function backoff(): array
    {
        return [30, 90, 180];
    }

    public function __construct(
        public string $integrationId
    ) {
        $this->onQueue('integrations');
    }

    public function handle(BrandCatalogService $catalogService): void
    {
        $integration = ProfessionalIntegration::query()
            ->where('id', $this->integrationId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration || empty($integration->access_token)) {
            return;
        }

        $brand = Professional::find($integration->professional_id);
        if (! $brand) {
            return;
        }

        try {
            $catalog = $catalogService->fetchBrandCatalog($brand);
        } catch (\Throwable $e) {
            Log::error('has_enabled_variants backfill: catalog fetch failed', [
                'integration_id' => $this->integrationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $writes = 0;
        $skipped = 0;
        $failures = 0;

        foreach ($catalog as $product) {
            $gid = $product['gid'] ?? '';
            if ($gid === '') {
                continue;
            }

            $variants = $product['variants'] ?? [];
            // No variants at all → single-SKU product → trivially "has enabled
            // variants". Otherwise check for at least one not-explicitly-disabled.
            $hasEnabled = empty($variants) || collect($variants)->contains(
                fn (array $v) => ($v['enabled'] ?? null) !== false
            );

            // Skip when the existing value already matches — avoids a
            // metafieldsSet round-trip for products where setVariantEnabledStates
            // has already written the right value.
            $existing = $product['metafields']['has_enabled_variants'] ?? null;
            if ($existing === $hasEnabled) {
                $skipped++;

                continue;
            }

            try {
                $result = $catalogService->writeHasEnabledVariants($integration, $gid, $hasEnabled);
                if ($result['success']) {
                    $writes++;
                } else {
                    $failures++;
                    Log::warning('has_enabled_variants backfill write failed', [
                        'integration_id' => $this->integrationId,
                        'product_gid' => $gid,
                        'userErrors' => $result['userErrors'],
                    ]);
                }
            } catch (\Throwable $e) {
                $failures++;
                Log::warning('has_enabled_variants backfill write exception', [
                    'integration_id' => $this->integrationId,
                    'product_gid' => $gid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $integration->mergeProviderMetadata([
            'has_enabled_variants_backfill_state' => $failures > 0 ? 'partial' : 'complete',
            'has_enabled_variants_backfill_at' => now()->toIso8601String(),
        ]);

        Log::info('has_enabled_variants backfill complete', [
            'integration_id' => $this->integrationId,
            'writes' => $writes,
            'skipped' => $skipped,
            'failures' => $failures,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $integration = ProfessionalIntegration::find($this->integrationId);
        $integration?->mergeProviderMetadata([
            'has_enabled_variants_backfill_state' => 'failed',
        ]);
    }
}
