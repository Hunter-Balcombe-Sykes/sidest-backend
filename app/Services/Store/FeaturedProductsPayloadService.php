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
     *   checkout_mode: string,
     *   favourite_brand_product_ids: string[]
     * }
     */
    public function build(string $professionalId, string $logContext = 'featured_products'): array
    {
        $defaultRate = (float) config('comet.store.default_commission_rate', 15);
        $maxFeatured = max(0, (int) config('comet.store.max_featured_products', 10));

        $checkoutMode = 'shopify';
        $brandStripeAccountId = null;
        $brandProfessionalId = null;
        try {
            $checkoutInfo = $this->resolveCheckoutInfoForAffiliate($professionalId);
            $checkoutMode = $checkoutInfo['checkout_mode'];
            $brandStripeAccountId = $checkoutInfo['brand_stripe_account_id'];
            $brandProfessionalId = $checkoutInfo['brand_professional_id'] ?? null;
        } catch (Throwable $e) {
            Log::warning('Could not resolve checkout info for affiliate; defaulting to shopify.', [
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);
        }

        $favouriteBrandProductIds = $this->resolveFavouriteBrandProductIds($brandProfessionalId);

        $payload = [
            'selected_products' => [],
            'default_product_selections' => [],
            'default_commission_rate' => $defaultRate,
            'max_featured_products' => $maxFeatured,
            'max_default_product_selections' => $maxFeatured,
            'checkout_mode' => $checkoutMode,
            'brand_stripe_account_id' => $brandStripeAccountId,
            'favourite_brand_product_ids' => $favouriteBrandProductIds,
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
            'favourite_brand_product_ids' => $favouriteBrandProductIds,
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
     * Resolve checkout mode, brand Stripe account ID, and brand professional ID for an affiliate.
     *
     * @return array{checkout_mode: string, brand_stripe_account_id: string|null, brand_professional_id: string|null}
     */
    private function resolveCheckoutInfoForAffiliate(string $affiliateProfessionalId): array
    {
        $default = ['checkout_mode' => 'shopify', 'brand_stripe_account_id' => null, 'brand_professional_id' => null];

        if ($affiliateProfessionalId === '') {
            return $default;
        }

        $brandProfessionalId = DB::table('core.brand_partner_links')
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->orderByDesc('created_at')
            ->value('brand_professional_id');

        $brandProfessionalId = trim((string) $brandProfessionalId);

        // If no brand partner link found, the professional may be viewing their own brand sitepage directly.
        if ($brandProfessionalId === '') {
            $brandProfessionalId = $affiliateProfessionalId;
        }

        $settings = DB::table('retail.brand_store_settings')
            ->where('professional_id', $brandProfessionalId)
            ->select(['checkout_mode'])
            ->first();

        $mode = strtolower(trim((string) ($settings?->checkout_mode ?? '')));
        $mode = in_array($mode, ['shopify', 'stripe'], true) ? $mode : 'shopify';

        $stripeAccountId = null;
        if ($mode === 'stripe') {
            $stripeAccountId = DB::table('core.professionals')
                ->where('id', $brandProfessionalId)
                ->value('stripe_connect_account_id');
            $stripeAccountId = trim((string) ($stripeAccountId ?? '')) ?: null;
        }

        return [
            'checkout_mode' => $mode,
            'brand_stripe_account_id' => $stripeAccountId,
            'brand_professional_id' => $brandProfessionalId,
        ];
    }

    /**
     * Resolve the brand's favourite product IDs from brand_store_settings.
     *
     * @return string[]
     */
    private function resolveFavouriteBrandProductIds(?string $brandProfessionalId): array
    {
        if ($brandProfessionalId === null || $brandProfessionalId === '') {
            return [];
        }

        try {
            $raw = DB::table('retail.brand_store_settings')
                ->where('professional_id', $brandProfessionalId)
                ->value('favourite_brand_product_ids');

            if ($raw === null) {
                return [];
            }

            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                // Postgres UUID[] arrays are returned as "{uuid1,uuid2}" — not valid JSON
                if (! is_array($decoded) && str_starts_with($raw, '{') && str_ends_with($raw, '}')) {
                    $inner = substr($raw, 1, -1);
                    $decoded = $inner !== '' ? explode(',', $inner) : [];
                }
            } else {
                $decoded = $raw;
            }

            if (! is_array($decoded)) {
                return [];
            }

            return array_values(array_filter(
                array_map(fn ($v) => trim((string) $v), $decoded),
                fn (string $v) => $v !== ''
            ));
        } catch (Throwable $e) {
            Log::warning('Could not resolve favourite brand product IDs.', [
                'brand_professional_id' => $brandProfessionalId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function resolveCheckoutModeForAffiliate(string $affiliateProfessionalId): string
    {
        return $this->resolveCheckoutInfoForAffiliate($affiliateProfessionalId)['checkout_mode'];
    }
}
