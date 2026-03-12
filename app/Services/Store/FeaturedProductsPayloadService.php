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
    private ?bool $selectionEnterpriseSupported = null;
    private ?bool $contractsTableAvailable = null;
    private ?bool $enterpriseProductsTableAvailable = null;
    private ?bool $enterprisesTableAvailable = null;
    private ?bool $primaryEnterpriseColumnAvailable = null;

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
        $supportsSelectionEnterprise = $this->supportsSelectionEnterpriseLink() && $this->hasSelectionsTable();
        $selectionEnterpriseId = $supportsSelectionEnterprise
            ? $this->resolveSelectionEnterpriseId($professionalId)
            : null;

        $columns = ['id', 'shopify_product_id', 'sort_order'];
        if ($supportsCommissionOverride) {
            $columns[] = 'commission_override';
        }
        if ($supportsSelectionEnterprise) {
            $columns[] = 'enterprise_id';
        }

        try {
            $query = ProfessionalSelection::query()
                ->where('professional_id', $professionalId)
                ->orderBy('sort_order');

            $selections = null;
            if ($supportsSelectionEnterprise && $selectionEnterpriseId !== null) {
                $enterpriseSelections = (clone $query)
                    ->where('enterprise_id', $selectionEnterpriseId)
                    ->get($columns);

                if ($enterpriseSelections->isNotEmpty()) {
                    $selections = $enterpriseSelections;
                }
            }

            if ($selections === null && $supportsSelectionEnterprise) {
                $legacySelections = (clone $query)
                    ->whereNull('enterprise_id')
                    ->get($columns);

                if ($legacySelections->isNotEmpty()) {
                    $selections = $legacySelections;
                }
            }

            if ($selections === null) {
                $selections = $query->get($columns);
            }
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

    public function supportsSelectionEnterpriseLink(): bool
    {
        if ($this->selectionEnterpriseSupported !== null) {
            return $this->selectionEnterpriseSupported;
        }

        try {
            $this->selectionEnterpriseSupported = DB::table('information_schema.columns')
                ->where('table_schema', 'retail')
                ->where('table_name', 'professional_selections')
                ->where('column_name', 'enterprise_id')
                ->exists();
        } catch (Throwable $e) {
            Log::warning('Could not verify enterprise_id column on retail.professional_selections.', [
                'error' => $e->getMessage(),
            ]);
            $this->selectionEnterpriseSupported = false;
        }

        return $this->selectionEnterpriseSupported;
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

    public function hasInfluencerPromoterContractsTable(): bool
    {
        if ($this->contractsTableAvailable !== null) {
            return $this->contractsTableAvailable;
        }

        $this->contractsTableAvailable = $this->hasTable('core.influencer_promoter_contracts');

        return $this->contractsTableAvailable;
    }

    public function hasEnterpriseProductsTable(): bool
    {
        if ($this->enterpriseProductsTableAvailable !== null) {
            return $this->enterpriseProductsTableAvailable;
        }

        $this->enterpriseProductsTableAvailable = $this->hasTable('retail.enterprise_products');

        return $this->enterpriseProductsTableAvailable;
    }

    public function hasEnterprisesTable(): bool
    {
        if ($this->enterprisesTableAvailable !== null) {
            return $this->enterprisesTableAvailable;
        }

        $this->enterprisesTableAvailable = $this->hasTable('core.enterprises');

        return $this->enterprisesTableAvailable;
    }

    /**
     * @return array{id: string, promoter_enterprise_id: string}|null
     */
    public function resolveActivePromoterContract(string $professionalId): ?array
    {
        if (
            $professionalId === ''
            || ! $this->hasInfluencerPromoterContractsTable()
            || ! $this->hasEnterprisesTable()
        ) {
            return null;
        }

        try {
            $row = DB::table('influencer_promoter_contracts as c')
                ->join('enterprises as e', 'e.id', '=', 'c.promoter_enterprise_id')
                ->where('c.influencer_professional_id', $professionalId)
                ->where('c.status', 'active')
                ->where('c.starts_at', '<=', now())
                ->where(function ($query) {
                    $query->whereNull('c.ends_at')
                        ->orWhere('c.ends_at', '>', now());
                })
                ->where('e.enterprise_type', 'promoter')
                ->whereNull('e.deleted_at')
                ->orderByDesc('c.starts_at')
                ->select('c.id', 'c.promoter_enterprise_id')
                ->first();
        } catch (Throwable $e) {
            Log::warning('Could not resolve active ambassador promoter contract.', [
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $row || ! isset($row->id, $row->promoter_enterprise_id)) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'promoter_enterprise_id' => (string) $row->promoter_enterprise_id,
        ];
    }

    public function resolveActivePromoterEnterpriseId(string $professionalId): ?string
    {
        $contract = $this->resolveActivePromoterContract($professionalId);

        return $contract['promoter_enterprise_id'] ?? null;
    }

    public function resolveSelectionEnterpriseId(string $professionalId): ?string
    {
        $activePromoterEnterpriseId = $this->resolveActivePromoterEnterpriseId($professionalId);
        if (is_string($activePromoterEnterpriseId) && $activePromoterEnterpriseId !== '') {
            return $activePromoterEnterpriseId;
        }

        return $this->resolvePrimaryEnterpriseId($professionalId);
    }

    /**
     * @param  array<int, string>  $shopifyProductIds
     */
    public function productsBelongToEnterprise(string $enterpriseId, array $shopifyProductIds): bool
    {
        $enterpriseId = trim($enterpriseId);
        if ($enterpriseId === '' || ! $this->hasEnterpriseProductsTable()) {
            return false;
        }

        $normalized = [];
        foreach ($shopifyProductIds as $value) {
            $productId = trim((string) $value);
            if ($productId !== '') {
                $normalized[$productId] = true;
            }
        }

        if ($normalized === []) {
            return true;
        }

        $productIds = array_keys($normalized);

        try {
            $count = DB::table('retail.enterprise_products')
                ->where('enterprise_id', $enterpriseId)
                ->where('is_active', true)
                ->whereIn('shopify_product_id', $productIds)
                ->count();
        } catch (Throwable $e) {
            Log::warning('Could not verify enterprise product ownership.', [
                'enterprise_id' => $enterpriseId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $count === count($productIds);
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

    private function hasTable(string $qualifiedTable): bool
    {
        try {
            $result = DB::selectOne('select to_regclass(?) as table_name', [$qualifiedTable]);

            return isset($result->table_name) && $result->table_name !== null;
        } catch (Throwable $e) {
            Log::warning('Could not verify table availability.', [
                'table' => $qualifiedTable,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function resolvePrimaryEnterpriseId(string $professionalId): ?string
    {
        if ($professionalId === '' || ! $this->supportsPrimaryEnterpriseColumn()) {
            return null;
        }

        try {
            $primaryEnterpriseId = DB::table('professionals')
                ->where('id', $professionalId)
                ->value('primary_enterprise_id');
        } catch (Throwable $e) {
            Log::warning('Could not resolve primary enterprise for professional.', [
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_string($primaryEnterpriseId) || trim($primaryEnterpriseId) === '') {
            return null;
        }

        return trim($primaryEnterpriseId);
    }

    private function supportsPrimaryEnterpriseColumn(): bool
    {
        if ($this->primaryEnterpriseColumnAvailable !== null) {
            return $this->primaryEnterpriseColumnAvailable;
        }

        try {
            $this->primaryEnterpriseColumnAvailable = DB::table('information_schema.columns')
                ->where('table_schema', 'core')
                ->where('table_name', 'professionals')
                ->where('column_name', 'primary_enterprise_id')
                ->exists();
        } catch (Throwable $e) {
            Log::warning('Could not verify primary_enterprise_id column on core.professionals.', [
                'error' => $e->getMessage(),
            ]);
            $this->primaryEnterpriseColumnAvailable = false;
        }

        return $this->primaryEnterpriseColumnAvailable;
    }
}
