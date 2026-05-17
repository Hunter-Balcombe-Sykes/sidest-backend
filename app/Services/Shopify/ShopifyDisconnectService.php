<?php

namespace App\Services\Shopify;

use App\Enums\BrandStatus;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Log;

/**
 * Shared brand-disconnect flow used by both the brand-facing
 * ShopifyIntegrationController::disconnect and the staff parity endpoint.
 *
 * Keeping the side-effect set in one place ensures a staff-initiated
 * disconnect leaves a brand in the same state as a brand-initiated one —
 * Shopify-side teardown + selection purge + integration row delete +
 * wizard reset + BrandProfile back to Onboarding.
 *
 * Best-effort by design: per-step failures inside the Shopify sweep are
 * already logged by ShopifyTeardownService; we still run the local
 * cleanup so we don't end up half-disconnected.
 */
class ShopifyDisconnectService
{
    public function __construct(
        private readonly ShopifyTeardownService $teardownService,
    ) {}

    /**
     * @param  array<string, mixed>  $actorContext  Free-form structured payload merged into the audit
     *                                              log entry — e.g. `['actor_staff_id' => '...']` or
     *                                              `['actor_professional_id' => '...']`.
     * @return array{teardown: array|null, selections_deleted: int}
     */
    public function disconnect(string $brandProfessionalId, array $actorContext = []): array
    {
        $integration = ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        $teardownSummary = null;
        if ($integration && ! empty($integration->access_token)) {
            try {
                $teardownSummary = $this->teardownService->teardownForIntegration($integration);
            } catch (\Throwable $e) {
                // ShopifyTeardownService logs per-step failures; this catch only fires
                // on a truly unexpected exception. Continue with local cleanup so the
                // brand isn't left half-disconnected.
                Log::error('Shopify teardown threw unexpectedly; continuing with local disconnect', array_merge($actorContext, [
                    'brand_professional_id' => $brandProfessionalId,
                    'error' => $e->getMessage(),
                ]));
            }
        }

        // Affiliate curated selections only make sense while this brand has
        // a catalog to curate from. Blow them away so the affiliates don't
        // end up with dangling GIDs pointing at deleted products.
        $deletedSelections = AffiliateProductSelection::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->delete();

        ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->delete();

        BrandStoreSettings::clearWizardProgress($brandProfessionalId);
        BrandProfile::where('professional_id', $brandProfessionalId)
            ->update([
                'brand_status' => BrandStatus::Onboarding->value,
                'setup_complete' => false,
            ]);

        Log::info('Shopify disconnected', array_merge($actorContext, [
            'brand_professional_id' => $brandProfessionalId,
            'teardown_summary' => $teardownSummary,
            'deleted_selections' => $deletedSelections,
        ]));

        return [
            'teardown' => $teardownSummary,
            'selections_deleted' => $deletedSelections,
        ];
    }
}
