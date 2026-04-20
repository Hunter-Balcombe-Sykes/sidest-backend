<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// V2: Simplified. Now only holds default_commission_rate and payout_hold_days. Per-product overrides moved to Shopify metafields.
class BrandStoreSettings extends Model
{
    use HasUuids;

    protected $table = 'brand.brand_store_settings';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'default_commission_rate',
        'payout_hold_days',
        'theme_id',
        'oxygen_deployment_token',
        'oxygen_storefront_id',
    ];

    protected $hidden = [
        'oxygen_deployment_token',
    ];

    protected $casts = [
        'default_commission_rate' => 'decimal:2',
        'payout_hold_days' => 'integer',
        'theme_id' => 'integer',
        // Encrypted at-rest using APP_KEY (AES-256-CBC via Laravel's encrypter)
        'oxygen_deployment_token' => 'encrypted',
    ];

    /**
     * Effective hold days for this brand, respecting the system minimum.
     */
    public function getEffectivePayoutHoldDaysAttribute(): int
    {
        $min = (int) config('sidest.store.min_payout_hold_days', 7);
        $systemDefault = (int) config('sidest.store.payout_hold_days', 7);

        $brandDays = $this->payout_hold_days;

        if ($brandDays === null) {
            return max($min, $systemDefault);
        }

        return max($min, $brandDays);
    }
}
