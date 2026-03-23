<?php

namespace App\Services\Store;

use App\Models\Core\Professional\BrandPartnerLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BrandProductCatalogService
{
    public function __construct(
        private readonly BrandPricingService $pricing,
        private readonly BrandProductSettingsService $settingsRows
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function affiliateVisibleProducts(string $affiliateProfessionalId, ?string $brandProfessionalId = null): array
    {
        $affiliateProfessionalId = trim($affiliateProfessionalId);
        $brandProfessionalId = $brandProfessionalId !== null ? trim($brandProfessionalId) : null;

        if ($affiliateProfessionalId === '') {
            return [];
        }

        $connectedBrandIds = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->pluck('brand_professional_id')
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($connectedBrandIds === []) {
            return [];
        }

        if ($brandProfessionalId !== null && $brandProfessionalId !== '') {
            if (! in_array($brandProfessionalId, $connectedBrandIds, true)) {
                return [];
            }

            $connectedBrandIds = [$brandProfessionalId];
        }

        $this->settingsRows->ensureSettingsRowsForBrands($connectedBrandIds);
        $brandEnterpriseFallback = DB::table('core.enterprise_brand_links as ebl')
            ->selectRaw('DISTINCT ON (ebl.brand_professional_id) ebl.brand_professional_id, ebl.enterprise_id')
            ->where('ebl.status', 'active')
            ->orderBy('ebl.brand_professional_id')
            ->orderByDesc('ebl.updated_at')
            ->orderByDesc('ebl.created_at')
            ->orderByDesc('ebl.id');

        $rows = DB::table('retail.brand_products as bp')
            ->join('core.brand_partner_links as l', function ($join) use ($affiliateProfessionalId): void {
                $join->on('l.brand_professional_id', '=', 'bp.brand_professional_id')
                    ->where('l.affiliate_professional_id', '=', $affiliateProfessionalId);
            })
            ->join('retail.brand_product_settings as bps', function ($join): void {
                $join->on('bps.brand_product_id', '=', 'bp.id')
                    ->on('bps.professional_id', '=', 'bp.brand_professional_id');
            })
            ->leftJoinSub($brandEnterpriseFallback, 'bfe', function ($join): void {
                $join->on('bfe.brand_professional_id', '=', 'bp.brand_professional_id');
            })
            ->leftJoin('retail.enterprise_products as ep', function ($join): void {
                $join->whereRaw('ep.enterprise_id = COALESCE(bp.enterprise_id, bfe.enterprise_id)')
                    ->whereRaw("
                        (
                            ep.shopify_product_id = bp.shopify_product_id
                            OR (
                                regexp_replace(bp.shopify_product_id, '[^0-9]', '', 'g') <> ''
                                AND regexp_replace(ep.shopify_product_id, '[^0-9]', '', 'g') = regexp_replace(bp.shopify_product_id, '[^0-9]', '', 'g')
                            )
                        )
                    ");
            })
            ->leftJoin('retail.brand_store_settings as bss', 'bss.professional_id', '=', 'bp.brand_professional_id')
            ->leftJoin('core.professionals as p', 'p.id', '=', 'bp.brand_professional_id')
            ->leftJoin('retail.brand_product_affiliate_overrides as o', function ($join) use ($affiliateProfessionalId): void {
                $join->on('o.brand_product_id', '=', 'bp.id')
                    ->where('o.affiliate_professional_id', '=', $affiliateProfessionalId)
                    ->where('o.override_type', '=', 'deny');
            })
            ->leftJoin('retail.brand_product_affiliate_overrides as oa', function ($join) use ($affiliateProfessionalId): void {
                $join->on('oa.brand_product_id', '=', 'bp.id')
                    ->where('oa.affiliate_professional_id', '=', $affiliateProfessionalId)
                    ->where('oa.override_type', '=', 'allow');
            })
            ->leftJoin('retail.brand_product_affiliate_settings as bpas', function ($join) use ($affiliateProfessionalId): void {
                $join->on('bpas.brand_product_id', '=', 'bp.id')
                    ->where('bpas.affiliate_professional_id', '=', $affiliateProfessionalId);
            })
            ->whereIn('bp.brand_professional_id', $connectedBrandIds)
            ->where('bp.is_sync_active', true)
            ->whereRaw("lower(COALESCE(bp.shopify_status, 'unknown')) IN ('active', 'archived')")
            ->where(function ($query): void {
                $query->whereRaw('COALESCE(bps.is_available, true) = true')
                    ->orWhereNotNull('oa.id');
            })
            ->whereNull('o.id')
            ->orderByDesc('bps.is_featured')
            ->orderBy('bps.sort_order')
            ->orderBy('bp.title')
            ->select([
                'bp.id as brand_product_id',
                'bp.brand_professional_id',
                'bp.shopify_product_id',
                'bp.title',
                'bp.handle',
                'bp.product_url',
                'bp.image_url',
                'bp.price_cents',
                'bp.currency_code',
                'bp.shopify_status',
                'bp.is_sync_active',
                'bp.last_synced_at',
                'bp.enterprise_id',
                'bp.metadata',
                'ep.title as enterprise_product_title',
                'ep.handle as enterprise_product_handle',
                'ep.product_url as enterprise_product_url',
                'ep.image_url as enterprise_product_image_url',
                'ep.price_cents as enterprise_product_price_cents',
                'ep.currency_code as enterprise_product_currency_code',
                'ep.metadata as enterprise_product_metadata',
                'bps.commission_override',
                'bps.discount_rate',
                'bps.custom_price',
                'bps.is_featured',
                'bps.is_available',
                'bps.sort_order',
                'bss.default_commission_rate',
                'p.display_name as brand_display_name',
                'p.handle as brand_handle',
                'bpas.commission_override as affiliate_commission_override',
                'bpas.discount_rate as affiliate_discount_rate',
                'bpas.custom_price as affiliate_custom_price',
            ])
            ->get();

        return $this->mapCatalogRows($rows);
    }

    /**
     * Full synced catalog for one or more managed brands.
     *
     * @param  array<int, string>  $brandProfessionalIds
     * @return array<int, array<string, mixed>>
     */
    public function managedCatalog(array $brandProfessionalIds): array
    {
        $brandProfessionalIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $brandProfessionalIds
        ), static fn (string $id): bool => $id !== '')));

        if ($brandProfessionalIds === []) {
            return [];
        }

        $this->settingsRows->ensureSettingsRowsForBrands($brandProfessionalIds);
        $brandEnterpriseFallback = DB::table('core.enterprise_brand_links as ebl')
            ->selectRaw('DISTINCT ON (ebl.brand_professional_id) ebl.brand_professional_id, ebl.enterprise_id')
            ->where('ebl.status', 'active')
            ->orderBy('ebl.brand_professional_id')
            ->orderByDesc('ebl.updated_at')
            ->orderByDesc('ebl.created_at')
            ->orderByDesc('ebl.id');

        $rows = DB::table('retail.brand_products as bp')
            ->leftJoinSub($brandEnterpriseFallback, 'bfe', function ($join): void {
                $join->on('bfe.brand_professional_id', '=', 'bp.brand_professional_id');
            })
            ->leftJoin('retail.enterprise_products as ep', function ($join): void {
                $join->whereRaw('ep.enterprise_id = COALESCE(bp.enterprise_id, bfe.enterprise_id)')
                    ->whereRaw("
                        (
                            ep.shopify_product_id = bp.shopify_product_id
                            OR (
                                regexp_replace(bp.shopify_product_id, '[^0-9]', '', 'g') <> ''
                                AND regexp_replace(ep.shopify_product_id, '[^0-9]', '', 'g') = regexp_replace(bp.shopify_product_id, '[^0-9]', '', 'g')
                            )
                        )
                    ");
            })
            ->leftJoin('retail.brand_product_settings as bps', function ($join): void {
                $join->on('bps.brand_product_id', '=', 'bp.id')
                    ->on('bps.professional_id', '=', 'bp.brand_professional_id');
            })
            ->leftJoin('retail.brand_store_settings as bss', 'bss.professional_id', '=', 'bp.brand_professional_id')
            ->leftJoin('core.professionals as p', 'p.id', '=', 'bp.brand_professional_id')
            ->whereIn('bp.brand_professional_id', $brandProfessionalIds)
            ->where('bp.is_sync_active', true)
            ->whereRaw("lower(COALESCE(bp.shopify_status, 'unknown')) IN ('active', 'archived')")
            ->orderBy('bp.brand_professional_id')
            ->orderByDesc(DB::raw('COALESCE(bps.is_featured, false)'))
            ->orderBy(DB::raw('COALESCE(bps.sort_order, 0)'))
            ->orderBy('bp.title')
            ->select([
                'bp.id as brand_product_id',
                'bp.brand_professional_id',
                'bp.shopify_product_id',
                'bp.title',
                'bp.handle',
                'bp.product_url',
                'bp.image_url',
                'bp.price_cents',
                'bp.currency_code',
                'bp.shopify_status',
                'bp.is_sync_active',
                'bp.last_synced_at',
                'bp.enterprise_id',
                'bp.metadata',
                'ep.title as enterprise_product_title',
                'ep.handle as enterprise_product_handle',
                'ep.product_url as enterprise_product_url',
                'ep.image_url as enterprise_product_image_url',
                'ep.price_cents as enterprise_product_price_cents',
                'ep.currency_code as enterprise_product_currency_code',
                'ep.metadata as enterprise_product_metadata',
                'bps.commission_override',
                'bps.discount_rate',
                'bps.custom_price',
                'bps.is_featured',
                'bps.is_available',
                'bps.sort_order',
                'bss.default_commission_rate',
                'p.display_name as brand_display_name',
                'p.handle as brand_handle',
            ])
            ->get();

        return $this->mapCatalogRows($rows);
    }

    /**
     * Selected storefront products after strict validity checks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function selectedProductsForProfessional(string $professionalId): array
    {
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return [];
        }

        $this->pruneInvalidSelectionsForProfessional($professionalId);

        $brandEnterpriseFallback = DB::table('core.enterprise_brand_links as ebl')
            ->selectRaw('DISTINCT ON (ebl.brand_professional_id) ebl.brand_professional_id, ebl.enterprise_id')
            ->where('ebl.status', 'active')
            ->orderBy('ebl.brand_professional_id')
            ->orderByDesc('ebl.updated_at')
            ->orderByDesc('ebl.created_at')
            ->orderByDesc('ebl.id');

        $rows = DB::table('retail.professional_selections as ps')
            ->join('retail.brand_products as bp', 'bp.id', '=', 'ps.brand_product_id')
            ->leftJoinSub($brandEnterpriseFallback, 'bfe', function ($join): void {
                $join->on('bfe.brand_professional_id', '=', 'bp.brand_professional_id');
            })
            ->leftJoin('retail.enterprise_products as ep', function ($join): void {
                $join->whereRaw('ep.enterprise_id = COALESCE(bp.enterprise_id, bfe.enterprise_id)')
                    ->whereRaw("
                        (
                            ep.shopify_product_id = bp.shopify_product_id
                            OR (
                                regexp_replace(bp.shopify_product_id, '[^0-9]', '', 'g') <> ''
                                AND regexp_replace(ep.shopify_product_id, '[^0-9]', '', 'g') = regexp_replace(bp.shopify_product_id, '[^0-9]', '', 'g')
                            )
                        )
                    ");
            })
            ->join('retail.brand_product_settings as bps', function ($join): void {
                $join->on('bps.brand_product_id', '=', 'bp.id')
                    ->on('bps.professional_id', '=', 'bp.brand_professional_id');
            })
            ->join('core.brand_partner_links as l', function ($join) use ($professionalId): void {
                $join->on('l.brand_professional_id', '=', 'bp.brand_professional_id')
                    ->where('l.affiliate_professional_id', '=', $professionalId);
            })
            ->leftJoin('retail.brand_product_affiliate_overrides as o', function ($join) use ($professionalId): void {
                $join->on('o.brand_product_id', '=', 'bp.id')
                    ->where('o.affiliate_professional_id', '=', $professionalId)
                    ->where('o.override_type', '=', 'deny');
            })
            ->leftJoin('retail.brand_product_affiliate_overrides as oa', function ($join) use ($professionalId): void {
                $join->on('oa.brand_product_id', '=', 'bp.id')
                    ->where('oa.affiliate_professional_id', '=', $professionalId)
                    ->where('oa.override_type', '=', 'allow');
            })
            ->leftJoin('retail.brand_store_settings as bss', 'bss.professional_id', '=', 'bp.brand_professional_id')
            ->leftJoin('core.professionals as p', 'p.id', '=', 'bp.brand_professional_id')
            ->leftJoin('retail.brand_product_affiliate_settings as bpas', function ($join) use ($professionalId): void {
                $join->on('bpas.brand_product_id', '=', 'bp.id')
                    ->where('bpas.affiliate_professional_id', '=', $professionalId);
            })
            ->where('ps.professional_id', $professionalId)
            ->where('bp.is_sync_active', true)
            ->whereRaw("lower(COALESCE(bp.shopify_status, 'unknown')) IN ('active', 'archived')")
            ->where(function ($query): void {
                $query->whereRaw('COALESCE(bps.is_available, true) = true')
                    ->orWhereNotNull('oa.id');
            })
            ->whereNull('o.id')
            ->orderBy('ps.sort_order')
            ->select([
                'ps.id as selection_id',
                'ps.sort_order as selection_sort_order',
                'bp.id as brand_product_id',
                'bp.brand_professional_id',
                'bp.shopify_product_id',
                'bp.title',
                'bp.handle',
                'bp.product_url',
                'bp.image_url',
                'bp.price_cents',
                'bp.currency_code',
                'bp.shopify_status',
                'bp.is_sync_active',
                'bp.last_synced_at',
                'bp.enterprise_id',
                'bp.metadata',
                'ep.title as enterprise_product_title',
                'ep.handle as enterprise_product_handle',
                'ep.product_url as enterprise_product_url',
                'ep.image_url as enterprise_product_image_url',
                'ep.price_cents as enterprise_product_price_cents',
                'ep.currency_code as enterprise_product_currency_code',
                'ep.metadata as enterprise_product_metadata',
                'bps.commission_override',
                'bps.discount_rate',
                'bps.custom_price',
                'bps.is_featured',
                'bps.is_available',
                'bps.sort_order',
                'bss.default_commission_rate',
                'p.display_name as brand_display_name',
                'p.handle as brand_handle',
                'bpas.commission_override as affiliate_commission_override',
                'bpas.discount_rate as affiliate_discount_rate',
                'bpas.custom_price as affiliate_custom_price',
            ])
            ->get();

        return $this->mapCatalogRows($rows, true);
    }

    private function pruneInvalidSelectionsForProfessional(string $professionalId): void
    {
        $invalidSelectionIds = DB::table('retail.professional_selections as ps')
            ->leftJoin('retail.brand_products as bp', 'bp.id', '=', 'ps.brand_product_id')
            ->leftJoin('retail.brand_product_settings as bps', function ($join): void {
                $join->on('bps.brand_product_id', '=', 'bp.id')
                    ->on('bps.professional_id', '=', 'bp.brand_professional_id');
            })
            ->leftJoin('core.brand_partner_links as l', function ($join) use ($professionalId): void {
                $join->on('l.brand_professional_id', '=', 'bp.brand_professional_id')
                    ->where('l.affiliate_professional_id', '=', $professionalId);
            })
            ->leftJoin('retail.brand_product_affiliate_overrides as o', function ($join) use ($professionalId): void {
                $join->on('o.brand_product_id', '=', 'bp.id')
                    ->where('o.affiliate_professional_id', '=', $professionalId)
                    ->where('o.override_type', '=', 'deny');
            })
            ->leftJoin('retail.brand_product_affiliate_overrides as oa', function ($join) use ($professionalId): void {
                $join->on('oa.brand_product_id', '=', 'bp.id')
                    ->where('oa.affiliate_professional_id', '=', $professionalId)
                    ->where('oa.override_type', '=', 'allow');
            })
            ->where('ps.professional_id', $professionalId)
            ->where(function ($query): void {
                $query->whereNull('bp.id')
                    ->orWhereNull('l.id')
                    ->orWhereRaw('COALESCE(bp.is_sync_active, false) = false')
                    ->orWhereRaw("lower(COALESCE(bp.shopify_status, 'unknown')) NOT IN ('active', 'archived')")
                    ->orWhere(function ($availabilityQuery): void {
                        $availabilityQuery
                            ->whereRaw('COALESCE(bps.is_available, true) = false')
                            ->whereNull('oa.id');
                    })
                    ->orWhereNotNull('o.id');
            })
            ->pluck('ps.id')
            ->filter(static fn ($id): bool => is_string($id) && trim($id) !== '')
            ->values()
            ->all();

        if ($invalidSelectionIds === []) {
            return;
        }

        DB::table('retail.professional_selections')
            ->whereIn('id', $invalidSelectionIds)
            ->delete();
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapCatalogRows(Collection $rows, bool $selectedRows = false): array
    {
        $defaultSystemCommission = $this->pricing->defaultCommissionRate();

        return $rows
            ->map(function ($row) use ($defaultSystemCommission, $selectedRows): array {
                $commissionOverride = $this->toNullableFloat($row->commission_override ?? null);
                $discountRate = $this->toNullableFloat($row->discount_rate ?? null);
                $customPrice = $this->toNullableFloat($row->custom_price ?? null);
                $affiliateCommissionOverride = $this->toNullableFloat($row->affiliate_commission_override ?? null);
                $affiliateDiscountRate = $this->toNullableFloat($row->affiliate_discount_rate ?? null);
                $affiliateCustomPrice = $this->toNullableFloat($row->affiliate_custom_price ?? null);
                $effectiveDiscountRate = $affiliateDiscountRate ?? $discountRate;
                $effectiveCustomPrice = $affiliateCustomPrice ?? $customPrice;
                $defaultCommissionRate = $this->toNullableFloat($row->default_commission_rate ?? null)
                    ?? $defaultSystemCommission;
                $shopifyProductId = (string) ($row->shopify_product_id ?? '');
                $catalogTitle = $this->nullableString($row->title ?? null);
                $enterpriseTitle = $this->nullableString($row->enterprise_product_title ?? null);
                $title = $this->resolveProductTitle($catalogTitle, $enterpriseTitle, $shopifyProductId);
                $handle = $this->nullableString($row->handle ?? null)
                    ?? $this->nullableString($row->enterprise_product_handle ?? null)
                    ?? '';
                $productUrl = $this->nullableString($row->product_url ?? null)
                    ?? $this->nullableString($row->enterprise_product_url ?? null)
                    ?? '';
                $imageUrl = $this->nullableString($row->image_url ?? null)
                    ?? $this->nullableString($row->enterprise_product_image_url ?? null)
                    ?? '';
                $catalogPriceCents = $this->toNullableInt($row->price_cents ?? null)
                    ?? $this->toNullableInt($row->enterprise_product_price_cents ?? null);
                $currencyCode = strtoupper(trim((string) (($this->nullableString($row->currency_code ?? null))
                    ?? ($this->nullableString($row->enterprise_product_currency_code ?? null))
                    ?? 'AUD')));
                $metadata = $this->mergeMetadata(
                    $this->normalizeMetadata($row->metadata ?? null),
                    $this->normalizeMetadata($row->enterprise_product_metadata ?? null)
                );

                $basePriceCents = $this->pricing->resolveBasePriceCents(
                    $catalogPriceCents,
                    $effectiveCustomPrice
                );
                $discountedPriceCents = $this->pricing->discountedPriceCents($basePriceCents, $effectiveDiscountRate);
                $effectiveCommissionRate = $this->pricing->effectiveCommissionRate(
                    $affiliateCommissionOverride,
                    $commissionOverride,
                    $defaultCommissionRate
                );

                $payload = [
                    'brand_product_id' => (string) ($row->brand_product_id ?? ''),
                    'brand_professional_id' => (string) ($row->brand_professional_id ?? ''),
                    'shopify_product_id' => $shopifyProductId,
                    'title' => $title,
                    'handle' => $handle,
                    'product_url' => $productUrl,
                    'image_url' => $imageUrl,
                    'currency_code' => $currencyCode,
                    'shopify_status' => (string) ($row->shopify_status ?? 'unknown'),
                    'is_sync_active' => (bool) ($row->is_sync_active ?? false),
                    'last_synced_at' => isset($row->last_synced_at) && $row->last_synced_at !== null
                        ? (string) $row->last_synced_at
                        : null,
                    'enterprise_id' => isset($row->enterprise_id) && $row->enterprise_id !== null
                        ? (string) $row->enterprise_id
                        : null,
                    'metadata' => $metadata,
                    'brand_display_name' => $this->nullableString($row->brand_display_name ?? null),
                    'brand_handle' => $this->nullableString($row->brand_handle ?? null),
                    'is_featured' => (bool) ($row->is_featured ?? false),
                    'sort_order' => (int) ($selectedRows ? ($row->selection_sort_order ?? 0) : ($row->sort_order ?? 0)),
                    'is_available' => (bool) ($row->is_available ?? true),
                    'default_commission_rate' => $defaultCommissionRate,
                    'commission_override' => $commissionOverride,
                    'affiliate_commission_override' => $affiliateCommissionOverride,
                    'effective_commission_rate' => $effectiveCommissionRate,
                    'discount_rate' => $discountRate,
                    'affiliate_discount_rate' => $affiliateDiscountRate,
                    'custom_price' => $customPrice,
                    'affiliate_custom_price' => $affiliateCustomPrice,
                    'base_price_cents' => $basePriceCents,
                    'discounted_price_cents' => $discountedPriceCents,
                ];

                if ($selectedRows) {
                    $payload['id'] = (string) ($row->selection_id ?? '');
                }

                return $payload;
            })
            ->values()
            ->all();
    }

    private function resolveProductTitle(?string $catalogTitle, ?string $enterpriseTitle, string $shopifyProductId): string
    {
        if ($enterpriseTitle !== null) {
            if ($catalogTitle === null || $this->isPlaceholderCatalogTitle($catalogTitle, $shopifyProductId)) {
                return $enterpriseTitle;
            }
        }

        if ($catalogTitle !== null) {
            return $catalogTitle;
        }

        if ($enterpriseTitle !== null) {
            return $enterpriseTitle;
        }

        $suffix = $this->extractNumericShopifyProductId($shopifyProductId) ?? $shopifyProductId;
        $suffix = trim($suffix);

        return $suffix === '' ? 'Shopify Product' : 'Shopify Product '.$suffix;
    }

    private function isPlaceholderCatalogTitle(string $title, string $shopifyProductId): bool
    {
        $normalized = mb_strtolower(trim($title));
        if ($normalized === '' || ! str_starts_with($normalized, 'shopify product')) {
            return false;
        }

        $digits = $this->extractNumericShopifyProductId($shopifyProductId);
        if ($digits === null) {
            return true;
        }

        return str_contains($normalized, $digits) || preg_match('/^shopify product\s+\d+$/', $normalized) === 1;
    }

    private function extractNumericShopifyProductId(string $shopifyProductId): ?string
    {
        if (preg_match('/(\d+)(?!.*\d)/', $shopifyProductId, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $catalogMetadata
     * @param  array<string, mixed>  $enterpriseMetadata
     * @return array<string, mixed>
     */
    private function mergeMetadata(array $catalogMetadata, array $enterpriseMetadata): array
    {
        if ($catalogMetadata === []) {
            return $enterpriseMetadata;
        }

        if ($enterpriseMetadata === []) {
            return $catalogMetadata;
        }

        return array_replace_recursive($enterpriseMetadata, $catalogMetadata);
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric((string) $value)) {
            return null;
        }

        return (float) $value;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric((string) $value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
