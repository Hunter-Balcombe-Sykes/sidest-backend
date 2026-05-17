<?php

namespace App\Console\Commands;

use App\Enums\BrandStatus;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pattern A Step 5 (embedded-rework remediation): alert when a Shopify
 * integration has been disconnected (access_token nulled, disconnected_at
 * set) but brand_profile.brand_status never propagated past the persistence
 * threshold. ReconcileStuckShopifyIntegrationsJob and the uninstall webhook
 * controller both write both sides in one path; this catches the rows where
 * the second write silently no-op'd (missing brand_profile row, deadlock,
 * future regression in the disconnect path).
 *
 * report()s a RuntimeException so Nightwatch surfaces it as an issue, then
 * exits clean — the schedule entry runs successfully and Nightwatch's alert
 * is the signal, not the exit code. Avoids spurious "Scheduled task failed"
 * scheduler log lines every run while the stuck state persists.
 */
class ReportStuckShopifyIntegrationsCommand extends Command
{
    protected $signature = 'partna:report-stuck-shopify-integrations
        {--days=7 : Persistence threshold; integrations stuck for fewer days are ignored}';

    protected $description = 'Alert via Nightwatch when Shopify integrations are stuck with disconnected_at set but brand_status not propagated past the persistence threshold.';

    private const SAMPLE_SIZE = 10;

    private const QUERY_LIMIT = 50;

    public function handle(): int
    {
        $thresholdDays = max(1, (int) $this->option('days'));

        $stuck = ProfessionalIntegration::query()
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->whereNull('access_token')
            ->whereNotNull('disconnected_at')
            ->where('disconnected_at', '<=', now()->subDays($thresholdDays))
            ->whereExists(function ($query): void {
                // Cross-schema join: integrations live in core.*, brand_profiles in brand.*.
                // whereColumn is fully qualified so the planner doesn't pick the wrong
                // professional_id when the test schema attaches both as separate dbs.
                $query->select(DB::raw(1))
                    ->from('brand.brand_profiles')
                    ->whereColumn('brand.brand_profiles.professional_id', 'core.professional_integrations.professional_id')
                    ->where('brand.brand_profiles.brand_status', '!=', BrandStatus::Disconnected->value);
            })
            ->orderBy('disconnected_at')
            ->limit(self::QUERY_LIMIT)
            ->get(['id', 'professional_id', 'shopify_shop_domain', 'disconnected_at']);

        if ($stuck->isEmpty()) {
            $this->info('No stuck Shopify integrations detected.');

            return self::SUCCESS;
        }

        $sample = $stuck->take(self::SAMPLE_SIZE)->map(fn ($row): array => [
            'professional_id' => (string) $row->professional_id,
            'shop_domain' => $row->shopify_shop_domain,
            'disconnected_at' => (string) $row->disconnected_at,
        ])->all();

        Log::warning('shopify.reconcile.silent_drift_detected', [
            'count' => $stuck->count(),
            'threshold_days' => $thresholdDays,
            'sample' => $sample,
        ]);

        report(new \RuntimeException(sprintf(
            'Pattern A Step 5: %d Shopify integration(s) stuck in impossible state '.
            '(access_token null, brand_status != disconnected) for >%d days. '.
            'See shopify.reconcile.silent_drift_detected log entry for sample IDs.',
            $stuck->count(),
            $thresholdDays
        )));

        $this->warn(sprintf(
            'Reported %d stuck integration(s) to Nightwatch via the exception path.',
            $stuck->count()
        ));

        return self::SUCCESS;
    }
}
