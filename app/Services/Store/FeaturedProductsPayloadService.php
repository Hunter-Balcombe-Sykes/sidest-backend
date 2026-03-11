<?php

namespace App\Services\Store;

use App\Models\Retail\ProfessionalSelection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeaturedProductsPayloadService
{
    private ?bool $commissionOverrideSupported = null;
    private ?bool $selectionsTableAvailable = null;

    /**
     * Build the canonical featured-products payload shape used by public and professional APIs.
     *
     * @param  mixed  $siteSettings
     * @return array{selected_products: array<int, array{id: string, shopify_product_id: string, sort_order: int, commission_override: float|null}>, default_commission_rate: float, max_featured_products: int}
     */
    public function build(string $professionalId, $siteSettings, string $logContext = 'featured_products'): array
    {
        $defaultRate = (float) config('comet.store.default_commission_rate', 15);
        $maxFeatured = max(0, (int) config('comet.store.max_featured_products', 10));
        $settings = is_array($siteSettings) ? $siteSettings : [];

        $fallback = [
            'selected_products' => $this->normalizeLegacySelectedProducts($settings, $maxFeatured),
            'default_commission_rate' => $defaultRate,
            'max_featured_products' => $maxFeatured,
        ];

        if ($professionalId === '' || ! $this->hasSelectionsTable()) {
            return $fallback;
        }

        $supportsCommissionOverride = $this->supportsCommissionOverride() && $this->hasSelectionsTable();

        $columns = ['id', 'shopify_product_id', 'sort_order'];
        if ($supportsCommissionOverride) {
            $columns[] = 'commission_override';
        }

        try {
            $selections = ProfessionalSelection::query()
                ->where('professional_id', $professionalId)
                ->orderBy('sort_order')
                ->get($columns);
        } catch (Throwable $e) {
            Log::warning('Featured products lookup failed; falling back to legacy site settings.', [
                'context' => $logContext,
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }

        return [
            'selected_products' => $this->toSelectionResponse($selections, $supportsCommissionOverride),
            'default_commission_rate' => $defaultRate,
            'max_featured_products' => $maxFeatured,
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

    public function supportsCommissionOverride(): bool
    {
        if ($this->commissionOverrideSupported !== null) {
            return $this->commissionOverrideSupported;
        }

        try {
            $this->commissionOverrideSupported = DB::table('information_schema.columns')
                ->where('table_schema', 'retail')
                ->where('table_name', 'professional_selections')
                ->where('column_name', 'commission_override')
                ->exists();
        } catch (Throwable $e) {
            Log::warning('Could not verify commission_override column on retail.professional_selections.', [
                'error' => $e->getMessage(),
            ]);
            $this->commissionOverrideSupported = false;
        }

        return $this->commissionOverrideSupported;
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<int, array{id: string, shopify_product_id: string, sort_order: int, commission_override: float|null}>
     */
    private function toSelectionResponse(Collection $rows, bool $supportsCommissionOverride): array
    {
        return $rows
            ->map(function ($row): array {
                return [
                    'id' => (string) $row->id,
                    'shopify_product_id' => (string) $row->shopify_product_id,
                    'sort_order' => (int) $row->sort_order,
                    'commission_override' => $row->commission_override !== null
                        ? (float) $row->commission_override
                        : null,
                ];
            })
            ->values()
            ->map(function (array $row) use ($supportsCommissionOverride): array {
                if (! $supportsCommissionOverride) {
                    $row['commission_override'] = null;
                }

                return $row;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $siteSettings
     * @return array<int, array{id: string, shopify_product_id: string, sort_order: int, commission_override: float|null}>
     */
    private function normalizeLegacySelectedProducts(array $siteSettings, int $maxFeatured): array
    {
        $selectedProducts = $siteSettings['selected_products'] ?? [];
        if (! is_array($selectedProducts)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach (array_values($selectedProducts) as $index => $product) {
            $normalizedProduct = $this->normalizeLegacyProduct($product, $index);
            if ($normalizedProduct === null) {
                continue;
            }

            $productKey = strtolower($normalizedProduct['shopify_product_id']);
            if (isset($seen[$productKey])) {
                continue;
            }

            $seen[$productKey] = true;
            $normalized[] = $normalizedProduct;

            if (count($normalized) >= $maxFeatured) {
                break;
            }
        }

        usort($normalized, function (array $a, array $b): int {
            if ($a['sort_order'] !== $b['sort_order']) {
                return $a['sort_order'] <=> $b['sort_order'];
            }

            return $a['shopify_product_id'] <=> $b['shopify_product_id'];
        });

        return array_values($normalized);
    }

    /**
     * @param  mixed  $product
     * @return array{id: string, shopify_product_id: string, sort_order: int, commission_override: float|null}|null
     */
    private function normalizeLegacyProduct($product, int $fallbackSortOrder): ?array
    {
        $shopifyProductId = '';
        $id = '';
        $sortOrder = $fallbackSortOrder;
        $commissionOverride = null;

        if (is_array($product)) {
            $shopifyProductId = trim((string) ($product['shopify_product_id'] ?? $product['id'] ?? ''));
            $id = trim((string) ($product['id'] ?? ''));
            $sortOrder = isset($product['sort_order']) && is_numeric($product['sort_order'])
                ? (int) $product['sort_order']
                : $fallbackSortOrder;

            if (array_key_exists('commission_override', $product)) {
                $commissionValue = $product['commission_override'];
                if ($commissionValue !== null && $commissionValue !== '' && is_numeric($commissionValue)) {
                    $commissionOverride = (float) $commissionValue;
                }
            }
        } elseif (is_string($product) || is_numeric($product)) {
            $shopifyProductId = trim((string) $product);
        } else {
            return null;
        }

        if ($shopifyProductId === '') {
            return null;
        }

        if ($id === '') {
            $id = $shopifyProductId;
        }

        return [
            'id' => $id,
            'shopify_product_id' => $shopifyProductId,
            'sort_order' => $sortOrder,
            'commission_override' => $commissionOverride,
        ];
    }
}
