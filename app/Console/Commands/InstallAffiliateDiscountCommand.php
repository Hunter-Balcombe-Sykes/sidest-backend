<?php

namespace App\Console\Commands;

use App\Jobs\Shopify\CreateShopifyAffiliateDiscountJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Console\Command;

/**
 * One-shot: dispatch CreateShopifyAffiliateDiscountJob for every connected
 * Shopify brand (or a specific one via --brand).
 *
 * Why we have this:
 *   The Partna Price Shopify Function + discount auto-install was added
 *   after existing brands had already completed OAuth. This command catches
 *   them up by dispatching the install job for each integration row.
 *
 * Safe to re-run:
 *   The job itself is idempotent — it checks for an existing automatic app
 *   discount backed by the function before creating one, and leaves state
 *   as 'pending' if the function hasn't propagated to the store yet (retry
 *   after the next `shopify app deploy`).
 */
class InstallAffiliateDiscountCommand extends Command
{
    protected $signature = 'sidest:install-affiliate-discount
        {--brand= : Only dispatch for this brand professional_id (UUID). If omitted, dispatches for every Shopify integration.}';

    protected $description = 'Install the Partna Price automatic discount on connected Shopify stores via the sidest-affiliate-discount Function.';

    public function handle(): int
    {
        $brandFilter = (string) $this->option('brand');

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

        $this->info(sprintf('Dispatching install jobs for %d brand(s).', $integrations->count()));

        foreach ($integrations as $integration) {
            CreateShopifyAffiliateDiscountJob::dispatch((string) $integration->id);
            $this->line("  dispatched for integration {$integration->id} (brand {$integration->professional_id})");
        }

        $this->info('All install jobs dispatched. Watch Horizon for completion + provider_metadata.sidest_discount_state for results.');

        return self::SUCCESS;
    }
}
