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
        $checkoutMode = $this->resolveCheckoutModeForAffiliate($professionalId);

        $payload = [
            'selected_products' => [],
            'default_product_selections' => [],
            'default_commission_rate' => $defaultRate,
            'max_featured_products' => $maxFeatured,
            'max_default_product_selections' => $maxFeatured,
            'checkout_mode' => $checkoutMode,
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

    private function resolveCheckoutModeForAffiliate(string $affiliateProfessionalId): string
    {
        if ($affiliateProfessionalId === '') {
            return 'shopify';
        }

        $brandProfessionalId = DB::table('core.brand_partner_links')
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->value('brand_professional_id');

        $brandProfessionalId = trim((string) $brandProfessionalId);
        if ($brandProfessionalId === '') {
            return 'shopify';
        }

        $mode = DB::table('retail.brand_store_settings')
            ->where('professional_id', $brandProfessionalId)
            ->value('checkout_mode');

        $mode = strtolower(trim((string) $mode));

        return in_array($mode, ['shopify', 'stripe'], true) ? $mode : 'shopify';
    }
}
