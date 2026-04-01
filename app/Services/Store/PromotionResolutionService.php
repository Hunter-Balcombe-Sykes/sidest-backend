<?php

namespace App\Services\Store;

use Illuminate\Support\Facades\DB;

class PromotionResolutionService
{
    /**
     * Request-level cache: active promotions keyed by brand_professional_id.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $brandPromotionCache = [];

    /**
     * Request-level cache: segment membership keyed by "{affiliateId}:{segmentsHash}".
     *
     * @var array<string, bool>
     */
    private array $segmentMembershipCache = [];

    /**
     * Fetch and cache all active promotions for a brand.
     * Subsequent calls for the same brand return from cache — 1 DB query per brand per request.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActivePromotionsForBrand(string $brandProfessionalId): array
    {
        if ($brandProfessionalId === '') {
            return [];
        }

        if (isset($this->brandPromotionCache[$brandProfessionalId])) {
            return $this->brandPromotionCache[$brandProfessionalId];
        }

        $rows = DB::table('retail.brand_promotions')
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('is_active', true)
            ->whereRaw('starts_at <= NOW()')
            ->whereRaw('ends_at > NOW()')
            ->orderByDesc('priority')
            ->orderByRaw("CASE affiliate_scope WHEN 'affiliates' THEN 0 WHEN 'segments' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE product_scope WHEN 'products' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get()
            ->all();

        $promotions = array_map(static fn (object $row): array => [
            'id' => (string) ($row->id ?? ''),
            'name' => (string) ($row->name ?? ''),
            'commission_rate' => isset($row->commission_rate) ? (float) $row->commission_rate : null,
            'discount_rate' => isset($row->discount_rate) ? (float) $row->discount_rate : null,
            'affiliate_scope' => (string) ($row->affiliate_scope ?? 'all'),
            'affiliate_segment_ids' => self::parseUuidArray($row->affiliate_segment_ids ?? null),
            'affiliate_ids' => self::parseUuidArray($row->affiliate_ids ?? null),
            'product_scope' => (string) ($row->product_scope ?? 'all'),
            'product_ids' => self::parseUuidArray($row->product_ids ?? null),
        ], $rows);

        $this->brandPromotionCache[$brandProfessionalId] = $promotions;

        return $promotions;
    }

    /**
     * Resolve the best matching active promotion for a given (brand, affiliate, product) triple.
     * Returns null when no promotion matches.
     *
     * @return array{commission_rate: float|null, discount_rate: float|null, promotion_id: string, promotion_name: string}|null
     */
    public function resolveActivePromotion(
        string $brandProfessionalId,
        string $affiliateProfessionalId,
        ?string $brandProductId
    ): ?array {
        $promotions = $this->getActivePromotionsForBrand($brandProfessionalId);

        if ($promotions === []) {
            return null;
        }

        $matched = $this->matchPromotion($promotions, $affiliateProfessionalId, $brandProductId);

        if ($matched === null) {
            return null;
        }

        return [
            'commission_rate' => $matched['commission_rate'],
            'discount_rate' => $matched['discount_rate'],
            'promotion_id' => $matched['id'],
            'promotion_name' => $matched['name'],
        ];
    }

    /**
     * Pure PHP matching — no DB queries (except segment membership which is cached).
     * Returns the first promotion that matches both product and affiliate scopes.
     *
     * @param  array<int, array<string, mixed>>  $promotions  Pre-sorted by precedence
     * @return array<string, mixed>|null
     */
    public function matchPromotion(array $promotions, string $affiliateProfessionalId, ?string $brandProductId): ?array
    {
        foreach ($promotions as $promotion) {
            if (! $this->matchesProductScope($promotion, $brandProductId)) {
                continue;
            }

            if (! $this->matchesAffiliateScope($promotion, $affiliateProfessionalId)) {
                continue;
            }

            return $promotion;
        }

        return null;
    }

    private function matchesProductScope(array $promotion, ?string $brandProductId): bool
    {
        if ($promotion['product_scope'] === 'all') {
            return true;
        }

        if ($brandProductId === null || $brandProductId === '') {
            return false;
        }

        return in_array($brandProductId, $promotion['product_ids'], true);
    }

    private function matchesAffiliateScope(array $promotion, string $affiliateProfessionalId): bool
    {
        if ($affiliateProfessionalId === '') {
            return $promotion['affiliate_scope'] === 'all';
        }

        return match ($promotion['affiliate_scope']) {
            'all' => true,
            'affiliates' => in_array($affiliateProfessionalId, $promotion['affiliate_ids'], true),
            'segments' => $this->affiliateIsInSegments($affiliateProfessionalId, $promotion['affiliate_segment_ids']),
            default => false,
        };
    }

    /**
     * Check if an affiliate is a member of any of the given segments.
     * Results are cached per (affiliateId, segmentsHash) within the request.
     */
    private function affiliateIsInSegments(string $affiliateProfessionalId, array $segmentIds): bool
    {
        if ($segmentIds === []) {
            return false;
        }

        sort($segmentIds);
        $cacheKey = $affiliateProfessionalId . ':' . implode(',', $segmentIds);

        if (isset($this->segmentMembershipCache[$cacheKey])) {
            return $this->segmentMembershipCache[$cacheKey];
        }

        $exists = DB::table('retail.brand_affiliate_segment_members')
            ->where('affiliate_professional_id', $affiliateProfessionalId)
            ->whereIn('segment_id', $segmentIds)
            ->exists();

        $this->segmentMembershipCache[$cacheKey] = $exists;

        return $exists;
    }

    /**
     * Parse a Postgres UUID[] string like "{uuid1,uuid2}" into a PHP array.
     */
    private static function parseUuidArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        $inner = trim($value, '{}');

        if ($inner === '') {
            return [];
        }

        return array_values(array_filter(
            explode(',', $inner),
            static fn (string $v): bool => $v !== ''
        ));
    }
}
