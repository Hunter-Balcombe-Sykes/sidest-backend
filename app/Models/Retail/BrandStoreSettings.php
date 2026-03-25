<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class BrandStoreSettings extends Model
{
    use HasUuids;

    protected $table = 'retail.brand_store_settings';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'default_commission_rate',
        'checkout_mode',
        'favourite_brand_product_ids',
        'payout_hold_days',
        'default_affiliate_theme_id',
        'default_affiliate_product_ids',
    ];

    protected $casts = [
        'default_commission_rate' => 'decimal:2',
        'payout_hold_days' => 'integer',
    ];

    /**
     * Effective hold days for this brand, respecting the system minimum.
     */
    public function getEffectivePayoutHoldDaysAttribute(): int
    {
        $min = (int) config('comet.store.min_payout_hold_days', 7);
        $systemDefault = (int) config('comet.store.payout_hold_days', 7);

        $brandDays = $this->payout_hold_days;

        if ($brandDays === null) {
            return max($min, $systemDefault);
        }

        return max($min, $brandDays);
    }

    /**
     * Postgres uuid[] <-> PHP string[] bridge.
     *
     * We intentionally avoid Laravel's generic "array" cast here because it
     * serializes values as JSON, while this column is native Postgres array.
     *
     * @return array<int, string>
     */
    public function getFavouriteBrandProductIdsAttribute(mixed $value): array
    {
        return self::decodeUuidArray($value);
    }

    public function setFavouriteBrandProductIdsAttribute(mixed $value): void
    {
        $this->attributes['favourite_brand_product_ids'] = self::encodeUuidArray($value);
    }

    // ── Default affiliate product IDs (uuid[]) ──────────────────────────

    /** @return array<int, string> */
    public function getDefaultAffiliateProductIdsAttribute(mixed $value): array
    {
        return self::decodeUuidArray($value);
    }

    public function setDefaultAffiliateProductIdsAttribute(mixed $value): void
    {
        $this->attributes['default_affiliate_product_ids'] = self::encodeUuidArray($value);
    }

    // ── Postgres uuid[] helpers ──────────────────────────────────────────

    /** @return array<int, string> */
    private static function decodeUuidArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(
                array_filter(
                    array_map(static fn (mixed $item): string => trim((string) $item), $value),
                    static fn (string $item): bool => $item !== ''
                )
            );
        }

        if (! is_string($value)) {
            return [];
        }

        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '{}') {
            return [];
        }

        $inner = trim($trimmed, '{}');
        if ($inner === '') {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static fn (string $item): string => trim($item, " \t\n\r\0\x0B\""),
                    explode(',', $inner)
                ),
                static fn (string $item): bool => $item !== ''
            )
        );
    }

    private static function encodeUuidArray(mixed $value): string
    {
        $ids = array_values(
            array_filter(
                array_map(static fn (mixed $item): string => trim((string) $item), Arr::wrap($value)),
                static fn (string $item): bool => $item !== ''
            )
        );

        if ($ids === []) {
            return '{}';
        }

        return '{'.implode(',', $ids).'}';
    }
}
