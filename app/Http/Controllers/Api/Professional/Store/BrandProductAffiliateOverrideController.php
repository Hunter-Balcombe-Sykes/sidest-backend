<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Retail\BrandProduct;
use App\Models\Retail\BrandProductAffiliateOverride;
use App\Services\Store\BrandAccessService;
use App\Services\Store\SelectionCleanupService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandProductAffiliateOverrideController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandAccessService $brandAccess,
        private readonly SelectionCleanupService $selectionCleanup
    ) {}

    /**
     * GET /store/affiliate-overrides
     */
    public function index(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $validator = Validator::make($request->query(), [
            'brand_professional_id' => ['required', 'uuid'],
            'affiliate_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $brandProfessionalId = trim((string) $validated['brand_professional_id']);
        $affiliateProfessionalId = trim((string) ($validated['affiliate_professional_id'] ?? ''));

        if (! $this->brandAccess->canManageBrand($professional, $brandProfessionalId)) {
            return $this->error('You are not permitted to manage overrides for this brand.', 403);
        }

        $query = BrandProductAffiliateOverride::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('override_type', 'deny')
            ->orderBy('affiliate_professional_id')
            ->orderBy('brand_product_id');

        if ($affiliateProfessionalId !== '') {
            $query->where('affiliate_professional_id', $affiliateProfessionalId);
        }

        $overrides = $query->get([
            'id',
            'brand_professional_id',
            'affiliate_professional_id',
            'brand_product_id',
            'override_type',
            'created_at',
            'updated_at',
        ]);

        return $this->success([
            'overrides' => $overrides,
        ]);
    }

    /**
     * PUT /store/affiliate-overrides/deny
     */
    public function upsertDeny(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['required', 'uuid'],
            'affiliate_professional_id' => ['required', 'uuid'],
            'brand_product_ids' => ['required', 'array', 'min:1'],
            'brand_product_ids.*' => ['uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $brandProfessionalId = trim((string) $validated['brand_professional_id']);
        $affiliateProfessionalId = trim((string) $validated['affiliate_professional_id']);
        $brandProductIds = $this->normalizeIds($validated['brand_product_ids'] ?? []);

        if (! $this->brandAccess->canManageBrand($professional, $brandProfessionalId)) {
            return $this->error('You are not permitted to manage overrides for this brand.', 403);
        }

        $matchingProductCount = BrandProduct::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereIn('id', $brandProductIds)
            ->count();

        if ($matchingProductCount !== count($brandProductIds)) {
            return $this->error('One or more brand_product_ids do not belong to this brand.', 422);
        }

        $now = now();
        $payload = [];
        foreach ($brandProductIds as $brandProductId) {
            $payload[] = [
                'id' => (string) Str::uuid(),
                'brand_professional_id' => $brandProfessionalId,
                'affiliate_professional_id' => $affiliateProfessionalId,
                'brand_product_id' => $brandProductId,
                'override_type' => 'deny',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        try {
            DB::transaction(function () use ($payload): void {
                DB::table('retail.brand_product_affiliate_overrides')->upsert(
                    $payload,
                    ['affiliate_professional_id', 'brand_product_id'],
                    ['brand_professional_id', 'override_type', 'updated_at']
                );
            });
        } catch (QueryException $e) {
            return $this->error('Failed to save deny overrides. Ensure affiliate and product links are valid.', 422);
        }

        $removedSelections = $this->selectionCleanup->removeSelectionsForAffiliateBrandProducts(
            $affiliateProfessionalId,
            $brandProductIds,
            'Product selections removed',
            '{count} selected product(s) were removed because the brand restricted those products for your account.'
        );

        return $this->success([
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'denied_brand_product_ids' => $brandProductIds,
            'cleaned_selection_count' => $removedSelections,
        ]);
    }

    /**
     * DELETE /store/affiliate-overrides/deny
     */
    public function removeDeny(Request $request)
    {
        $professional = $this->currentProfessional($request);

        $validator = Validator::make($request->all(), [
            'brand_professional_id' => ['required', 'uuid'],
            'affiliate_professional_id' => ['required', 'uuid'],
            'brand_product_ids' => ['sometimes', 'array'],
            'brand_product_ids.*' => ['uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $brandProfessionalId = trim((string) $validated['brand_professional_id']);
        $affiliateProfessionalId = trim((string) $validated['affiliate_professional_id']);
        $brandProductIds = $this->normalizeIds($validated['brand_product_ids'] ?? []);

        if (! $this->brandAccess->canManageBrand($professional, $brandProfessionalId)) {
            return $this->error('You are not permitted to manage overrides for this brand.', 403);
        }

        $query = BrandProductAffiliateOverride::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->where('override_type', 'deny');

        if ($brandProductIds !== []) {
            $query->whereIn('brand_product_id', $brandProductIds);
        }

        $deleted = $query->delete();

        return $this->success([
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'removed_count' => $deleted,
        ]);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids
        ), static fn (string $id): bool => $id !== '')));
    }
}
