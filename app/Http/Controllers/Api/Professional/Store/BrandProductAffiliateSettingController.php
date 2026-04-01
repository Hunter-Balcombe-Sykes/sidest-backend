<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Retail\BrandProduct;
use App\Models\Retail\BrandProductAffiliateSetting;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Store\BrandAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandProductAffiliateSettingController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly BrandAccessService $brandAccess,
        private readonly NotificationPublisher $notificationPublisher,
    ) {}

    /**
     * GET /store/affiliate-product-settings
     */
    public function index(\App\Http\Requests\Api\Professional\Store\IndexBrandProductAffiliateSettingRequest $request)
    {
        $professional = $this->currentProfessional($request);

        $validated = $request->validated();
        $brandProfessionalId = trim((string) $validated['brand_professional_id']);
        $affiliateProfessionalId = trim((string) ($validated['affiliate_professional_id'] ?? ''));
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 100);

        if (! $this->brandAccess->canManageBrand($professional, $brandProfessionalId)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        $query = BrandProductAffiliateSetting::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->orderBy('affiliate_professional_id')
            ->orderBy('brand_product_id');

        if ($affiliateProfessionalId !== '') {
            $query->where('affiliate_professional_id', $affiliateProfessionalId);
        }

        $settings = $query->paginate(
            $perPage,
            [
                'id',
                'brand_professional_id',
                'affiliate_professional_id',
                'brand_product_id',
                'commission_override',
                'discount_rate',
                'custom_price',
                'created_at',
                'updated_at',
            ],
            'page',
            $page
        );

        return $this->paginated($settings, 'settings');
    }

    /**
     * PUT /store/affiliate-product-settings
     *
     * Upserts per-affiliate pricing overrides for one or more products.
     *
     * Body:
     * {
     *   "brand_professional_id": "uuid",
     *   "affiliate_professional_id": "uuid",
     *   "settings": [
     *     {
     *       "brand_product_id": "uuid",
     *       "commission_override": 18.0,   // nullable
     *       "discount_rate": 5.0,          // nullable
     *       "custom_price": null           // nullable
     *     }
     *   ]
     * }
     */
    public function upsert(\App\Http\Requests\Api\Professional\Store\UpsertBrandProductAffiliateSettingRequest $request)
    {
        $professional = $this->currentProfessional($request);

        $validated = $request->validated();
        $brandProfessionalId = trim((string) $validated['brand_professional_id']);
        $affiliateProfessionalId = trim((string) $validated['affiliate_professional_id']);
        $settings = array_map(
            static function (array $setting): array {
                $setting['brand_product_id'] = strtolower(trim((string) $setting['brand_product_id']));

                return $setting;
            },
            $validated['settings']
        );

        if (! $this->brandAccess->canManageBrand($professional, $brandProfessionalId)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        $brandProductIds = array_values(array_unique(array_map(
            static fn (array $s): string => trim((string) $s['brand_product_id']),
            $settings
        )));

        if (count($brandProductIds) !== count($settings)) {
            return $this->error('Duplicate brand_product_id values are not allowed in settings.', 422);
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
        foreach ($settings as $setting) {
            $payload[] = [
                'id' => (string) Str::uuid(),
                'brand_professional_id' => $brandProfessionalId,
                'affiliate_professional_id' => $affiliateProfessionalId,
                'brand_product_id' => $setting['brand_product_id'],
                'commission_override' => isset($setting['commission_override']) && $setting['commission_override'] !== '' && $setting['commission_override'] !== null
                    ? (float) $setting['commission_override']
                    : null,
                'discount_rate' => isset($setting['discount_rate']) && $setting['discount_rate'] !== '' && $setting['discount_rate'] !== null
                    ? (float) $setting['discount_rate']
                    : null,
                'custom_price' => isset($setting['custom_price']) && $setting['custom_price'] !== '' && $setting['custom_price'] !== null
                    ? (float) $setting['custom_price']
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        try {
            DB::transaction(function () use ($payload): void {
                DB::table('retail.brand_product_affiliate_settings')->upsert(
                    $payload,
                    ['affiliate_professional_id', 'brand_product_id'],
                    ['brand_professional_id', 'commission_override', 'discount_rate', 'custom_price', 'updated_at']
                );
            });
        } catch (QueryException $e) {
            return $this->error('Failed to save affiliate product settings. Ensure the affiliate is connected to this brand.', 422);
        }

        // Notify the affiliate that their commission settings were updated
        try {
            $yearWeek = now()->format('o-W');
            $this->notificationPublisher->publish(
                professionalId: $affiliateProfessionalId,
                frontendType: 'Info',
                category: 'catalog_changes',
                title: 'Commission settings updated',
                body: 'Your commission settings for this brand have been updated.',
                dedupeKey: "catalog.commission_changed.{$affiliateProfessionalId}.{$yearWeek}",
                ctaUrl: '/account/store',
                retentionConfigKey: 'catalog_change',
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Commission settings notification failed', [
                'affiliate_professional_id' => $affiliateProfessionalId,
                'message' => $e->getMessage(),
            ]);
        }

        return $this->success([
            'brand_professional_id' => $brandProfessionalId,
            'affiliate_professional_id' => $affiliateProfessionalId,
            'updated_brand_product_ids' => $brandProductIds,
        ]);
    }

    /**
     * DELETE /store/affiliate-product-settings
     */
    public function remove(\App\Http\Requests\Api\Professional\Store\RemoveBrandProductAffiliateSettingRequest $request)
    {
        $professional = $this->currentProfessional($request);

        $validated = $request->validated();
        $brandProfessionalId = trim((string) $validated['brand_professional_id']);
        $affiliateProfessionalId = trim((string) $validated['affiliate_professional_id']);
        $brandProductIds = $this->normalizeIds($validated['brand_product_ids'] ?? []);

        if (! $this->brandAccess->canManageBrand($professional, $brandProfessionalId)) {
            return $this->error('You are not permitted to manage settings for this brand.', 403);
        }

        $query = BrandProductAffiliateSetting::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('affiliate_professional_id', $affiliateProfessionalId);

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
