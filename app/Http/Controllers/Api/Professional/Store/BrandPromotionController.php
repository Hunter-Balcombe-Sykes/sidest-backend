<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Store\CloneBrandPromotionRequest;
use App\Http\Requests\Api\Professional\Store\PreviewBrandPromotionRequest;
use App\Http\Requests\Api\Professional\Store\StoreBrandPromotionRequest;
use App\Http\Requests\Api\Professional\Store\UpdateBrandPromotionRequest;
use App\Models\Retail\BrandPromotion;
use App\Services\Store\BrandAccessService;
use App\Services\Store\BrandPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrandPromotionController extends ApiController
{
    use ResolveCurrentProfessional;

    private const MAX_ACTIVE_PROMOTIONS_PER_BRAND = 50;
    private const PREVIEW_AFFILIATES_SAMPLE_LIMIT = 100;
    private const PREVIEW_PRODUCTS_SAMPLE_LIMIT = 200;

    public function __construct(
        private readonly BrandAccessService $brandAccess,
        private readonly BrandPricingService $pricing,
    ) {}

    /**
     * GET /store/promotions?status=active|scheduled|ended|all&brand_professional_id=
     */
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        [$brandId, $error] = $this->resolveBrandId($request, $pro);
        if ($error !== null) {
            return $error;
        }

        $status = $request->query('status', 'all');

        $query = BrandPromotion::query()
            ->where('brand_professional_id', $brandId)
            ->orderByDesc('created_at');

        $now = now();

        match ($status) {
            'active' => $query->where('is_active', true)->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now),
            'ended' => $query->where(fn ($q) => $q->where('ends_at', '<=', $now)->orWhere('is_active', false)),
            default => null,
        };

        $perPage = min((int) ($request->query('per_page', 50)), 100);
        $promotions = $query->paginate($perPage);

        return $this->success([
            'data' => collect($promotions->items())->map(fn ($p) => $this->buildPayload($p))->values()->all(),
            'meta' => [
                'current_page' => $promotions->currentPage(),
                'per_page' => $promotions->perPage(),
                'total' => $promotions->total(),
                'last_page' => $promotions->lastPage(),
            ],
        ]);
    }

    /**
     * POST /store/promotions
     */
    public function store(StoreBrandPromotionRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $validated = $request->validated();

        $brandId = trim((string) $validated['brand_professional_id']);

        if (! $this->brandAccess->canManageBrand($pro, $brandId)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        [$affiliateIds, $affiliateSegmentIds, $productIds, $ownershipError] = $this->validateTargetOwnership(
            $brandId,
            $validated,
            null,
            false
        );

        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $isActive = (bool) ($validated['is_active'] ?? true);

        $promotion = DB::transaction(function () use ($brandId, $validated, $affiliateIds, $affiliateSegmentIds, $productIds, $isActive): ?BrandPromotion {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['promotions:' . $brandId]);
            $now = now();

            if ($isActive) {
                $activeCount = BrandPromotion::query()
                    ->where('brand_professional_id', $brandId)
                    ->where('is_active', true)
                    ->where('ends_at', '>', $now)
                    ->count();

                if ($activeCount >= self::MAX_ACTIVE_PROMOTIONS_PER_BRAND) {
                    return null;
                }
            }

            return BrandPromotion::create([
                'brand_professional_id' => $brandId,
                'name' => trim((string) $validated['name']),
                'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'commission_rate' => isset($validated['commission_rate']) ? (float) $validated['commission_rate'] : null,
                'discount_rate' => isset($validated['discount_rate']) ? (float) $validated['discount_rate'] : null,
                'affiliate_scope' => (string) $validated['affiliate_scope'],
                'affiliate_ids' => $affiliateIds,
                'affiliate_segment_ids' => $affiliateSegmentIds,
                'product_scope' => (string) $validated['product_scope'],
                'product_ids' => $productIds,
                'priority' => (int) ($validated['priority'] ?? 0),
                'is_active' => $isActive,
            ]);
        });

        if (! $promotion instanceof BrandPromotion) {
            return $this->error('Maximum of ' . self::MAX_ACTIVE_PROMOTIONS_PER_BRAND . ' active promotions per brand reached.', 422);
        }

        return $this->success($this->buildPayload($promotion), 201);
    }

    /**
     * GET /store/promotions/{promotionId}
     */
    public function show(Request $request, string $promotionId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $promotion = BrandPromotion::find($promotionId);

        if (! $promotion) {
            return $this->error('Promotion not found.', 404);
        }

        if (! $this->brandAccess->canManageBrand($pro, (string) $promotion->brand_professional_id)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        return $this->success($this->buildPayload($promotion));
    }

    /**
     * PATCH /store/promotions/{promotionId}
     */
    public function update(UpdateBrandPromotionRequest $request, string $promotionId): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $validated = $request->validated();

        $promotion = BrandPromotion::find($promotionId);

        if (! $promotion) {
            return $this->error('Promotion not found.', 404);
        }

        if (! $this->brandAccess->canManageBrand($pro, (string) $promotion->brand_professional_id)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        if ($promotion->ends_at !== null && $promotion->ends_at->isPast()) {
            return $this->error('Cannot modify a promotion that has already ended.', 422);
        }

        $finalCommissionRate = array_key_exists('commission_rate', $validated)
            ? ($validated['commission_rate'] !== null ? (float) $validated['commission_rate'] : null)
            : ($promotion->commission_rate !== null ? (float) $promotion->commission_rate : null);
        $finalDiscountRate = array_key_exists('discount_rate', $validated)
            ? ($validated['discount_rate'] !== null ? (float) $validated['discount_rate'] : null)
            : ($promotion->discount_rate !== null ? (float) $promotion->discount_rate : null);

        if ($finalCommissionRate === null && $finalDiscountRate === null) {
            return $this->error('At least one of commission_rate or discount_rate is required.', 422);
        }

        $brandId = (string) $promotion->brand_professional_id;
        $isReactivating = isset($validated['is_active']) && (bool) $validated['is_active'] && ! (bool) $promotion->is_active;

        [$affiliateIds, $affiliateSegmentIds, $productIds, $ownershipError] = $this->validateTargetOwnership(
            $brandId,
            $validated,
            $promotion,
            true
        );

        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $saved = DB::transaction(function () use ($promotion, $validated, $affiliateIds, $affiliateSegmentIds, $productIds, $brandId, $promotionId, $isReactivating): bool {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['promotions:' . $brandId]);
            $now = now();

            if ($isReactivating) {
                $activeCount = BrandPromotion::query()
                    ->where('brand_professional_id', $brandId)
                    ->where('is_active', true)
                    ->where('ends_at', '>', $now)
                    ->where('id', '!=', $promotionId)
                    ->count();

                if ($activeCount >= self::MAX_ACTIVE_PROMOTIONS_PER_BRAND) {
                    return false;
                }
            }

            if (isset($validated['name'])) {
                $promotion->name = trim((string) $validated['name']);
            }
            if (array_key_exists('description', $validated)) {
                $promotion->description = isset($validated['description']) ? trim((string) $validated['description']) : null;
            }
            if (isset($validated['starts_at'])) {
                $promotion->starts_at = $validated['starts_at'];
            }
            if (isset($validated['ends_at'])) {
                $promotion->ends_at = $validated['ends_at'];
            }
            if (array_key_exists('commission_rate', $validated)) {
                $promotion->commission_rate = isset($validated['commission_rate']) ? (float) $validated['commission_rate'] : null;
            }
            if (array_key_exists('discount_rate', $validated)) {
                $promotion->discount_rate = isset($validated['discount_rate']) ? (float) $validated['discount_rate'] : null;
            }
            if (isset($validated['affiliate_scope'])) {
                $promotion->affiliate_scope = (string) $validated['affiliate_scope'];
            }
            if (isset($validated['product_scope'])) {
                $promotion->product_scope = (string) $validated['product_scope'];
            }
            if (isset($validated['priority'])) {
                $promotion->priority = (int) $validated['priority'];
            }
            if (isset($validated['is_active'])) {
                $promotion->is_active = (bool) $validated['is_active'];
            }

            if ($affiliateIds !== null) {
                $promotion->affiliate_ids = $affiliateIds;
            }
            if ($affiliateSegmentIds !== null) {
                $promotion->affiliate_segment_ids = $affiliateSegmentIds;
            }
            if ($productIds !== null) {
                $promotion->product_ids = $productIds;
            }

            $promotion->save();

            return true;
        });

        if (! $saved) {
            return $this->error('Maximum of ' . self::MAX_ACTIVE_PROMOTIONS_PER_BRAND . ' active promotions per brand reached.', 422);
        }

        return $this->success($this->buildPayload($promotion));
    }

    /**
     * DELETE /store/promotions/{promotionId}
     */
    public function destroy(Request $request, string $promotionId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $promotion = BrandPromotion::find($promotionId);

        if (! $promotion) {
            return $this->error('Promotion not found.', 404);
        }

        if (! $this->brandAccess->canManageBrand($pro, (string) $promotion->brand_professional_id)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        $promotion->delete();

        return $this->success(['deleted' => true]);
    }

    /**
     * POST /store/promotions/{promotionId}/clone
     */
    public function clone(CloneBrandPromotionRequest $request, string $promotionId): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $validated = $request->validated();

        $source = BrandPromotion::find($promotionId);

        if (! $source) {
            return $this->error('Promotion not found.', 404);
        }

        if (! $this->brandAccess->canManageBrand($pro, (string) $source->brand_professional_id)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        $brandId = (string) $source->brand_professional_id;

        $commissionRate = array_key_exists('commission_rate', $validated)
            ? ($validated['commission_rate'] !== null ? (float) $validated['commission_rate'] : null)
            : ($source->commission_rate !== null ? (float) $source->commission_rate : null);
        $discountRate = array_key_exists('discount_rate', $validated)
            ? ($validated['discount_rate'] !== null ? (float) $validated['discount_rate'] : null)
            : ($source->discount_rate !== null ? (float) $source->discount_rate : null);

        if ($commissionRate === null && $discountRate === null) {
            return $this->error('At least one of commission_rate or discount_rate is required.', 422);
        }

        $cloneAffiliateScope = (string) ($validated['affiliate_scope'] ?? $source->affiliate_scope);
        $cloneProductScope = (string) ($validated['product_scope'] ?? $source->product_scope);
        $cloneInput = [
            'affiliate_scope' => $cloneAffiliateScope,
            'affiliate_ids' => array_key_exists('affiliate_ids', $validated) ? $validated['affiliate_ids'] : $source->affiliate_ids,
            'affiliate_segment_ids' => array_key_exists('affiliate_segment_ids', $validated) ? $validated['affiliate_segment_ids'] : $source->affiliate_segment_ids,
            'product_scope' => $cloneProductScope,
            'product_ids' => array_key_exists('product_ids', $validated) ? $validated['product_ids'] : $source->product_ids,
        ];

        [$affiliateIds, $affiliateSegmentIds, $productIds, $ownershipError] = $this->validateTargetOwnership(
            $brandId,
            $cloneInput,
            null,
            false
        );

        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $cloneIsActive = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : true;

        $cloned = DB::transaction(function () use ($validated, $source, $brandId, $commissionRate, $discountRate, $cloneAffiliateScope, $cloneProductScope, $affiliateIds, $affiliateSegmentIds, $productIds, $cloneIsActive): ?BrandPromotion {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['promotions:' . $brandId]);
            $now = now();

            if ($cloneIsActive) {
                $activeCount = BrandPromotion::query()
                    ->where('brand_professional_id', $brandId)
                    ->where('is_active', true)
                    ->where('ends_at', '>', $now)
                    ->count();

                if ($activeCount >= self::MAX_ACTIVE_PROMOTIONS_PER_BRAND) {
                    return null;
                }
            }

            return BrandPromotion::create([
                'brand_professional_id' => $brandId,
                'name' => trim((string) $validated['name']),
                'description' => array_key_exists('description', $validated)
                    ? (isset($validated['description']) ? trim((string) $validated['description']) : null)
                    : $source->description,
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'],
                'commission_rate' => $commissionRate,
                'discount_rate' => $discountRate,
                'affiliate_scope' => $cloneAffiliateScope,
                'affiliate_ids' => $affiliateIds,
                'affiliate_segment_ids' => $affiliateSegmentIds,
                'product_scope' => $cloneProductScope,
                'product_ids' => $productIds,
                'priority' => array_key_exists('priority', $validated) ? (int) $validated['priority'] : (int) $source->priority,
                'is_active' => $cloneIsActive,
                'notification_sent_at' => null,
                'end_notification_sent_at' => null,
            ]);
        });

        if (! $cloned instanceof BrandPromotion) {
            return $this->error('Maximum of ' . self::MAX_ACTIVE_PROMOTIONS_PER_BRAND . ' active promotions per brand reached.', 422);
        }

        return $this->success($this->buildPayload($cloned), 201);
    }

    /**
     * POST /store/promotions/preview
     * Dry-run: show affected affiliates/products + rate deltas without saving.
     */
    public function preview(PreviewBrandPromotionRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $validated = $request->validated();

        $brandId = trim((string) $validated['brand_professional_id']);

        if (! $this->brandAccess->canManageBrand($pro, $brandId)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        [$affiliateIds, $affiliateSegmentIds, $productIds, $ownershipError] = $this->validateTargetOwnership(
            $brandId,
            $validated,
            null,
            false
        );

        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $affiliateScope = (string) $validated['affiliate_scope'];
        $productScope = (string) $validated['product_scope'];
        $promoCommissionRate = isset($validated['commission_rate']) ? (float) $validated['commission_rate'] : null;
        $promoDiscountRate = isset($validated['discount_rate']) ? (float) $validated['discount_rate'] : null;

        $affiliates = $this->resolvePreviewAffiliates(
            $brandId,
            $affiliateScope,
            $affiliateIds ?? [],
            $affiliateSegmentIds ?? [],
            self::PREVIEW_AFFILIATES_SAMPLE_LIMIT
        );
        $products = $this->resolvePreviewProducts(
            $brandId,
            $productScope,
            $productIds ?? [],
            self::PREVIEW_PRODUCTS_SAMPLE_LIMIT
        );

        $totalAffiliates = $this->countPreviewAffiliates(
            $brandId,
            $affiliateScope,
            $affiliateIds ?? [],
            $affiliateSegmentIds ?? []
        );
        $totalProducts = $this->countPreviewProducts($brandId, $productScope, $productIds ?? []);

        $affectedAffiliates = [];
        foreach ($affiliates as $affiliate) {
            $affiliateId = (string) ($affiliate['affiliate_professional_id'] ?? '');
            $currentCommission = $this->resolveCurrentStaticCommissionRate($brandId, $affiliateId, null);
            $affectedAffiliates[] = [
                'id' => $affiliateId,
                'display_name' => $affiliate['display_name'] ?? null,
                'current_commission_rate' => $currentCommission,
                'promotion_commission_rate' => $promoCommissionRate,
            ];
        }

        $affectedProducts = [];
        foreach ($products as $product) {
            $productId = (string) ($product['id'] ?? '');
            $currentDiscount = isset($product['discount_rate']) ? (float) $product['discount_rate'] : null;
            $affectedProducts[] = [
                'id' => $productId,
                'title' => $product['title'] ?? null,
                'current_discount_rate' => $currentDiscount,
                'promotion_discount_rate' => $promoDiscountRate,
            ];
        }

        return $this->success([
            'affected_affiliates' => $affectedAffiliates,
            'affected_products' => $affectedProducts,
            'total_affected_affiliates' => $totalAffiliates,
            'total_affected_products' => $totalProducts,
        ]);
    }

    /**
     * GET /store/promotions/{promotionId}/analytics
     */
    public function analytics(Request $request, string $promotionId): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $promotion = BrandPromotion::find($promotionId);

        if (! $promotion) {
            return $this->error('Promotion not found.', 404);
        }

        $brandId = (string) $promotion->brand_professional_id;

        if (! $this->brandAccess->can($pro, $brandId, BrandAccessService::CAPABILITY_ANALYTICS_FINANCIAL_READ)) {
            return $this->error('You are not permitted to view financial analytics for this brand.', 403);
        }

        $entries = DB::table('retail.commission_ledger_entries as cle')
            ->where('cle.brand_professional_id', $brandId)
            ->where('cle.entry_type', 'accrual')
            ->whereRaw("cle.calculation_metadata->>'promotion_id' = ?", [$promotionId])
            ->selectRaw('
                COUNT(DISTINCT cle.order_id) as total_orders,
                SUM(cle.amount_cents) as total_commission_cents,
                COUNT(DISTINCT cle.affiliate_professional_id) as total_affiliates_impacted
            ')
            ->first();

        $promotionOrderIds = DB::table('retail.commission_ledger_entries as cle')
            ->select('cle.order_id')
            ->where('cle.brand_professional_id', $brandId)
            ->where('cle.entry_type', 'accrual')
            ->whereNotNull('cle.order_id')
            ->whereRaw("cle.calculation_metadata->>'promotion_id' = ?", [$promotionId])
            ->distinct();

        $revenue = DB::table('retail.orders as o')
            ->joinSub($promotionOrderIds, 'promo_orders', function ($join): void {
                $join->on('promo_orders.order_id', '=', 'o.id');
            })
            ->where('o.brand_professional_id', $brandId)
            ->sum('o.total_price_cents');

        $topAffiliates = DB::table('retail.commission_ledger_entries as cle')
            ->join('core.professionals as p', 'p.id', '=', 'cle.affiliate_professional_id')
            ->where('cle.brand_professional_id', $brandId)
            ->where('cle.entry_type', 'accrual')
            ->whereRaw("cle.calculation_metadata->>'promotion_id' = ?", [$promotionId])
            ->selectRaw('cle.affiliate_professional_id, p.display_name, COUNT(DISTINCT cle.order_id) as orders, SUM(cle.amount_cents) as commission_cents')
            ->groupBy('cle.affiliate_professional_id', 'p.display_name')
            ->orderByDesc('commission_cents')
            ->limit(10)
            ->get()
            ->all();

        $topProducts = DB::table('retail.commission_ledger_entries as cle')
            ->join('retail.order_items as oi', 'oi.id', '=', 'cle.order_item_id')
            ->join('retail.brand_products as bp', 'bp.id', '=', 'oi.brand_product_id')
            ->where('cle.brand_professional_id', $brandId)
            ->where('cle.entry_type', 'accrual')
            ->whereRaw("cle.calculation_metadata->>'promotion_id' = ?", [$promotionId])
            ->selectRaw('bp.id, bp.title, COUNT(*) as orders, SUM(cle.amount_cents) as commission_cents')
            ->groupBy('bp.id', 'bp.title')
            ->orderByDesc('commission_cents')
            ->limit(10)
            ->get()
            ->all();

        return $this->success([
            'promotion_id' => $promotionId,
            'name' => (string) $promotion->name,
            'starts_at' => $promotion->starts_at?->toISOString(),
            'ends_at' => $promotion->ends_at?->toISOString(),
            'total_orders' => (int) ($entries->total_orders ?? 0),
            'total_revenue_cents' => (int) ($revenue ?? 0),
            'total_commission_cents' => (int) ($entries->total_commission_cents ?? 0),
            'total_affiliates_impacted' => (int) ($entries->total_affiliates_impacted ?? 0),
            'top_affiliates' => array_map(static fn ($r): array => [
                'affiliate_professional_id' => (string) $r->affiliate_professional_id,
                'display_name' => $r->display_name,
                'orders' => (int) $r->orders,
                'commission_cents' => (int) $r->commission_cents,
            ], $topAffiliates),
            'top_products' => array_map(static fn ($r): array => [
                'brand_product_id' => (string) $r->id,
                'title' => $r->title,
                'orders' => (int) $r->orders,
                'commission_cents' => (int) $r->commission_cents,
            ], $topProducts),
        ]);
    }

    /**
     * @return array{0: string, 1: JsonResponse|null}
     */
    private function resolveBrandId(Request $request, $pro): array
    {
        $requestedId = trim((string) $request->input('brand_professional_id', ''));

        if ($requestedId === '') {
            if ($this->brandAccess->isBrandProfessional($pro)) {
                $requestedId = (string) $pro->id;
            } else {
                return ['', $this->error('brand_professional_id is required for this account type.', 422)];
            }
        }

        if (! $this->brandAccess->canManageBrand($pro, $requestedId)) {
            return ['', $this->error('You are not permitted to manage settings for this brand.', 403)];
        }

        return [$requestedId, null];
    }

    /**
     * Validate that referenced affiliate_ids, affiliate_segment_ids, and product_ids belong to the brand.
     *
     * @return array{0: array<int, string>|null, 1: array<int, string>|null, 2: array<int, string>|null, 3: JsonResponse|null}
     */
    private function validateTargetOwnership(
        string $brandId,
        array $validated,
        ?BrandPromotion $existing = null,
        bool $partialUpdate = false
    ): array {
        $existingAffiliateScope = $existing?->affiliate_scope;
        $existingProductScope = $existing?->product_scope;

        $affiliateScopeProvided = array_key_exists('affiliate_scope', $validated);
        $productScopeProvided = array_key_exists('product_scope', $validated);

        $affiliateScope = (string) ($validated['affiliate_scope'] ?? ($existingAffiliateScope ?? 'all'));
        $productScope = (string) ($validated['product_scope'] ?? ($existingProductScope ?? 'all'));

        $affiliateScopeChanged = $partialUpdate && $affiliateScopeProvided && $existing !== null
            && $affiliateScope !== (string) $existingAffiliateScope;
        $productScopeChanged = $partialUpdate && $productScopeProvided && $existing !== null
            && $productScope !== (string) $existingProductScope;

        $affiliateIdsProvided = array_key_exists('affiliate_ids', $validated);
        $affiliateSegmentIdsProvided = array_key_exists('affiliate_segment_ids', $validated);
        $productIdsProvided = array_key_exists('product_ids', $validated);

        $affiliateIds = null;
        if ($affiliateScope === 'affiliates') {
            if (! ($partialUpdate && ! $affiliateIdsProvided && ! $affiliateScopeChanged)) {
                $rawIds = $this->sanitizeUuidArray($validated['affiliate_ids'] ?? []);

                if ($rawIds !== []) {
                    $validCount = DB::table('core.brand_partner_links')
                        ->where('brand_professional_id', $brandId)
                        ->whereIn('affiliate_professional_id', $rawIds)
                        ->count();

                    if ($validCount !== count($rawIds)) {
                        return [null, null, null, $this->error('One or more affiliate_ids are not connected to this brand.', 422)];
                    }
                }

                $affiliateIds = $rawIds;
            }
        } elseif (! $partialUpdate || $affiliateScopeChanged || $affiliateIdsProvided) {
            $affiliateIds = [];
        }

        $affiliateSegmentIds = null;
        if ($affiliateScope === 'segments') {
            if (! ($partialUpdate && ! $affiliateSegmentIdsProvided && ! $affiliateScopeChanged)) {
                $rawIds = $this->sanitizeUuidArray($validated['affiliate_segment_ids'] ?? []);

                if ($rawIds !== []) {
                    $validCount = DB::table('retail.brand_affiliate_segments')
                        ->where('brand_professional_id', $brandId)
                        ->whereIn('id', $rawIds)
                        ->count();

                    if ($validCount !== count($rawIds)) {
                        return [null, null, null, $this->error('One or more affiliate_segment_ids do not belong to this brand.', 422)];
                    }
                }

                $affiliateSegmentIds = $rawIds;
            }
        } elseif (! $partialUpdate || $affiliateScopeChanged || $affiliateSegmentIdsProvided) {
            $affiliateSegmentIds = [];
        }

        $productIds = null;
        if ($productScope === 'products') {
            if (! ($partialUpdate && ! $productIdsProvided && ! $productScopeChanged)) {
                $rawIds = $this->sanitizeUuidArray($validated['product_ids'] ?? []);

                if ($rawIds !== []) {
                    $validCount = DB::table('retail.brand_products')
                        ->where('brand_professional_id', $brandId)
                        ->whereIn('id', $rawIds)
                        ->count();

                    if ($validCount !== count($rawIds)) {
                        return [null, null, null, $this->error('One or more product_ids do not belong to this brand.', 422)];
                    }
                }

                $productIds = $rawIds;
            }
        } elseif (! $partialUpdate || $productScopeChanged || $productIdsProvided) {
            $productIds = [];
        }

        return [$affiliateIds, $affiliateSegmentIds, $productIds, null];
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function sanitizeUuidArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $value
        ), static fn (string $id): bool => $id !== '')));
    }

    /**
     * @param  array<int, string>  $affiliateIds
     * @param  array<int, string>  $affiliateSegmentIds
     * @return array<int, array<string, mixed>>
     */
    private function resolvePreviewAffiliates(string $brandId, string $scope, array $affiliateIds, array $affiliateSegmentIds, int $limit): array
    {
        return match ($scope) {
            'affiliates' => DB::table('core.brand_partner_links as bpl')
                ->join('core.professionals as p', 'p.id', '=', 'bpl.affiliate_professional_id')
                ->where('bpl.brand_professional_id', $brandId)
                ->whereIn('bpl.affiliate_professional_id', $affiliateIds)
                ->select(['bpl.affiliate_professional_id', 'p.display_name'])
                ->limit($limit)
                ->get()
                ->map(static fn ($r): array => ['affiliate_professional_id' => (string) $r->affiliate_professional_id, 'display_name' => $r->display_name])
                ->all(),
            'segments' => DB::table('retail.brand_affiliate_segment_members as m')
                ->join('retail.brand_affiliate_segments as s', 's.id', '=', 'm.segment_id')
                ->join('core.professionals as p', 'p.id', '=', 'm.affiliate_professional_id')
                ->where('s.brand_professional_id', $brandId)
                ->whereIn('m.segment_id', $affiliateSegmentIds)
                ->select(['m.affiliate_professional_id', 'p.display_name'])
                ->distinct()
                ->limit($limit)
                ->get()
                ->map(static fn ($r): array => ['affiliate_professional_id' => (string) $r->affiliate_professional_id, 'display_name' => $r->display_name])
                ->all(),
            default => DB::table('core.brand_partner_links as bpl')
                ->join('core.professionals as p', 'p.id', '=', 'bpl.affiliate_professional_id')
                ->where('bpl.brand_professional_id', $brandId)
                ->select(['bpl.affiliate_professional_id', 'p.display_name'])
                ->limit($limit)
                ->get()
                ->map(static fn ($r): array => ['affiliate_professional_id' => (string) $r->affiliate_professional_id, 'display_name' => $r->display_name])
                ->all(),
        };
    }

    /**
     * @param  array<int, string>  $affiliateIds
     * @param  array<int, string>  $affiliateSegmentIds
     */
    private function countPreviewAffiliates(string $brandId, string $scope, array $affiliateIds, array $affiliateSegmentIds): int
    {
        return match ($scope) {
            'affiliates' => (int) DB::table('core.brand_partner_links as bpl')
                ->where('bpl.brand_professional_id', $brandId)
                ->whereIn('bpl.affiliate_professional_id', $affiliateIds)
                ->count(),
            'segments' => (int) DB::table('retail.brand_affiliate_segment_members as m')
                ->join('retail.brand_affiliate_segments as s', 's.id', '=', 'm.segment_id')
                ->where('s.brand_professional_id', $brandId)
                ->whereIn('m.segment_id', $affiliateSegmentIds)
                ->distinct('m.affiliate_professional_id')
                ->count('m.affiliate_professional_id'),
            default => (int) DB::table('core.brand_partner_links as bpl')
                ->where('bpl.brand_professional_id', $brandId)
                ->count(),
        };
    }

    /**
     * @param  array<int, string>  $productIds
     * @return array<int, array<string, mixed>>
     */
    private function resolvePreviewProducts(string $brandId, string $scope, array $productIds, int $limit): array
    {
        $query = DB::table('retail.brand_products as bp')
            ->leftJoin('retail.brand_product_settings as bps', function ($join): void {
                $join->on('bps.brand_product_id', '=', 'bp.id')
                    ->on('bps.professional_id', '=', 'bp.brand_professional_id');
            })
            ->where('bp.brand_professional_id', $brandId)
            ->where('bp.is_sync_active', true)
            ->select(['bp.id', 'bp.title', 'bps.discount_rate'])
            ->limit($limit);

        if ($scope === 'products') {
            $query->whereIn('bp.id', $productIds);
        }

        return $query->get()
            ->map(static fn ($r): array => [
                'id' => (string) $r->id,
                'title' => $r->title,
                'discount_rate' => isset($r->discount_rate) ? (float) $r->discount_rate : null,
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $productIds
     */
    private function countPreviewProducts(string $brandId, string $scope, array $productIds): int
    {
        $query = DB::table('retail.brand_products as bp')
            ->where('bp.brand_professional_id', $brandId)
            ->where('bp.is_sync_active', true);

        if ($scope === 'products') {
            $query->whereIn('bp.id', $productIds);
        }

        return (int) $query->count();
    }

    /**
     * Resolve current static commission rate for an affiliate/brand pair (no promotion applied).
     */
    private function resolveCurrentStaticCommissionRate(string $brandId, string $affiliateId, ?string $brandProductId): float
    {
        if ($brandProductId !== null) {
            $override = DB::table('retail.brand_product_affiliate_settings')
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('brand_product_id', $brandProductId)
                ->whereNotNull('commission_override')
                ->value('commission_override');

            if ($override !== null) {
                return (float) $override;
            }

            $brandOverride = DB::table('retail.brand_product_settings')
                ->where('professional_id', $brandId)
                ->where('brand_product_id', $brandProductId)
                ->whereNotNull('commission_override')
                ->value('commission_override');

            if ($brandOverride !== null) {
                return (float) $brandOverride;
            }
        }

        $brandDefault = DB::table('retail.brand_store_settings')
            ->where('professional_id', $brandId)
            ->whereNotNull('default_commission_rate')
            ->value('default_commission_rate');

        return $brandDefault !== null
            ? (float) $brandDefault
            : $this->pricing->defaultCommissionRate();
    }

    private function buildPayload(BrandPromotion $promotion): array
    {
        return [
            'id' => (string) $promotion->id,
            'brand_professional_id' => (string) $promotion->brand_professional_id,
            'name' => (string) $promotion->name,
            'description' => $promotion->description,
            'starts_at' => $promotion->starts_at?->toISOString(),
            'ends_at' => $promotion->ends_at?->toISOString(),
            'commission_rate' => $promotion->commission_rate !== null ? (float) $promotion->commission_rate : null,
            'discount_rate' => $promotion->discount_rate !== null ? (float) $promotion->discount_rate : null,
            'affiliate_scope' => (string) $promotion->affiliate_scope,
            'affiliate_ids' => $promotion->affiliate_ids,
            'affiliate_segment_ids' => $promotion->affiliate_segment_ids,
            'product_scope' => (string) $promotion->product_scope,
            'product_ids' => $promotion->product_ids,
            'priority' => (int) $promotion->priority,
            'is_active' => (bool) $promotion->is_active,
            'notification_sent_at' => $promotion->notification_sent_at?->toISOString(),
            'end_notification_sent_at' => $promotion->end_notification_sent_at?->toISOString(),
            'created_at' => $promotion->created_at?->toISOString(),
            'updated_at' => $promotion->updated_at?->toISOString(),
        ];
    }
}
