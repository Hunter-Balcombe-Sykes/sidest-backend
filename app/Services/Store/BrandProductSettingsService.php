<?php

namespace App\Services\Store;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BrandProductSettingsService
{
    /**
     * Ensure every synced brand product has a corresponding settings row.
     */
    public function ensureSettingsRowsForBrand(string $brandProfessionalId): int
    {
        $brandProfessionalId = trim($brandProfessionalId);

        if ($brandProfessionalId === '') {
            return 0;
        }

        $rows = DB::table('retail.brand_products as bp')
            ->leftJoin('retail.brand_product_settings as bps', function ($join): void {
                $join->on('bps.brand_product_id', '=', 'bp.id')
                    ->on('bps.professional_id', '=', 'bp.brand_professional_id');
            })
            ->where('bp.brand_professional_id', $brandProfessionalId)
            ->whereNull('bps.id')
            ->select([
                'bp.id as brand_product_id',
                'bp.brand_professional_id',
                'bp.shopify_product_id',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $now = now();
        $payload = $rows
            ->map(static function ($row) use ($now): array {
                return [
                    'id' => (string) Str::uuid(),
                    'professional_id' => (string) $row->brand_professional_id,
                    'brand_product_id' => (string) $row->brand_product_id,
                    'shopify_product_id' => (string) $row->shopify_product_id,
                    'is_approved' => false,
                    'is_featured' => false,
                    'is_available' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values()
            ->all();

        DB::table('retail.brand_product_settings')->upsert(
            $payload,
            ['professional_id', 'brand_product_id'],
            ['shopify_product_id', 'updated_at']
        );

        return count($payload);
    }

    /**
     * @param  array<int, string>  $brandProfessionalIds
     */
    public function ensureSettingsRowsForBrands(array $brandProfessionalIds): int
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $brandProfessionalIds
        ), static fn (string $id): bool => $id !== '')));

        $inserted = 0;

        foreach ($normalized as $brandProfessionalId) {
            $inserted += $this->ensureSettingsRowsForBrand($brandProfessionalId);
        }

        return $inserted;
    }
}
