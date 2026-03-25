<?php

namespace App\Services\Store;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeaturedProductsPayloadService
{
    public function __construct(
        private readonly BrandProductCatalogService $catalog
    ) {}

    private ?bool $selectionsTableAvailable = null;

    /**
     * Build the canonical featured-products payload shape used by public and professional APIs.
     *
     * @return array{
     *   selected_products: array<int, array<string, mixed>>,
     *   default_product_selections: array<int, array<string, mixed>>,
     *   default_commission_rate: float,
     *   max_featured_products: int,
     *   max_default_product_selections: int,
     *   checkout_mode: string
     * }
     */
    public function build(string $professionalId, string $logContext = 'featured_products'): array
    {
        $defaultRate = (float) config('comet.store.default_commission_rate', 15);
        $maxFeatured = max(0, (int) config('comet.store.max_featured_products', 10));

        $checkoutMode = 'shopify';
        $brandStripeAccountId = null;
        try {
            $checkoutInfo = $this->resolveCheckoutInfoForAffiliate($professionalId);
            $checkoutMode = $checkoutInfo['checkout_mode'];
            $brandStripeAccountId = $checkoutInfo['brand_stripe_account_id'];
            Log::info('[DEBUG_CHECKOUT] resolveCheckoutInfoForAffiliate result', [
                'professional_id' => $professionalId,
                'checkout_info' => $checkoutInfo,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not resolve checkout info for affiliate; defaulting to shopify.', [
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $payload = [
            'selected_products' => [],
            'default_product_selections' => [],
            'default_commission_rate' => $defaultRate,
            'max_featured_products' => $maxFeatured,
            'max_default_product_selections' => $maxFeatured,
            'checkout_mode' => $checkoutMode,
            'brand_stripe_account_id' => $brandStripeAccountId,
        ];

        if ($professionalId === '' || ! $this->hasSelectionsTable()) {
            return $payload;
        }

        try {
            $selectedProducts = $this->catalog->selectedProductsForProfessional($professionalId);
        } catch (Throwable $e) {
            Log::warning('Featured products lookup failed.', [
                'context' => $logContext,
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }

        return [
            'selected_products' => array_slice(array_values($selectedProducts), 0, $maxFeatured),
            'default_product_selections' => array_slice(array_values($selectedProducts), 0, $maxFeatured),
            'default_commission_rate' => $defaultRate,
            'max_featured_products' => $maxFeatured,
            'max_default_product_selections' => $maxFeatured,
            'checkout_mode' => $checkoutMode,
            'brand_stripe_account_id' => $brandStripeAccountId,
        ];
    }

    public function hasSelectionsTable(): bool
    {
        if ($this->selectionsTableAvailable !== null) {
            return $this->selectionsTableAvailable;
        }

        try {
            $result = DB::selectOne("select to_regclass('retail.professional_selections') as table_name");
            $this->selectionsTableAvailable = isset($result->table_name) && $result->table_name !== null;
        } catch (Throwable $e) {
            Log::warning('Could not verify retail.professional_selections availability.', [
                'error' => $e->getMessage(),
            ]);
            $this->selectionsTableAvailable = false;
        }

        return $this->selectionsTableAvailable;
    }

    /**
     * Resolve checkout mode and brand Stripe account ID for an affiliate's storefront.
     *
     * @return array{checkout_mode: string, brand_stripe_account_id: string|null}
     */
    private function resolveCheckoutInfoForAffiliate(string $affiliateProfessionalId): array
    {
        $default = ['checkout_mode' => 'shopify', 'brand_stripe_account_id' => null];

        if ($affiliateProfessionalId === '') {
            return $default;
        }

        $brandProfessionalId = DB::table('core.brand_partner_links')
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->value('brand_professional_id');

        $brandProfessionalId = trim((string) $brandProfessionalId);

        // If no brand partner link found, the professional may be viewing their own brand sitepage directly.
        if ($brandProfessionalId === '') {
            $brandProfessionalId = $affiliateProfessionalId;
        }

        Log::info('[DEBUG_CHECKOUT] brand lookup', [
            'affiliate' => $affiliateProfessionalId,
            'resolved_brand' => $brandProfessionalId,
        ]);

        $settings = DB::table('retail.brand_store_settings')
            ->where('professional_id', $brandProfessionalId)
            ->select(['checkout_mode'])
            ->first();

        Log::info('[DEBUG_CHECKOUT] brand_store_settings result', [
            'brand' => $brandProfessionalId,
            'settings' => $settings ? (array) $settings : null,
        ]);

        $mode = strtolower(trim((string) ($settings?->checkout_mode ?? '')));
        $mode = in_array($mode, ['shopify', 'stripe'], true) ? $mode : 'shopify';

        $stripeAccountId = null;
        if ($mode === 'stripe') {
            $stripeAccountId = DB::table('core.professionals')
                ->where('id', $brandProfessionalId)
                ->value('stripe_connect_account_id');
            $stripeAccountId = trim((string) ($stripeAccountId ?? '')) ?: null;
        }

        return ['checkout_mode' => $mode, 'brand_stripe_account_id' => $stripeAccountId];
    }

    private function resolveCheckoutModeForAffiliate(string $affiliateProfessionalId): string
    {
        return $this->resolveCheckoutInfoForAffiliate($affiliateProfessionalId)['checkout_mode'];
    }
}
