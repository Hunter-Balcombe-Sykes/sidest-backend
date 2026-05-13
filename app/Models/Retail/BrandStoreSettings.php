<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Per-brand store configuration. Holds financial defaults (`default_commission_rate`,
 * `payout_hold_days`), the storefront theme preset (`theme_id`, integer 1–5), and
 * Shopify Hydrogen/Oxygen wizard state (`oxygen_storefront_id`, `hydrogen_install_confirmed`,
 * `oxygen_deployment_token` — encrypted at-rest, hidden from serialisation).
 */
class BrandStoreSettings extends BaseModel
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
        'oxygen_storefront_id',
        'hydrogen_install_confirmed',
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
     * Effective hold days for this brand. Brands can explicitly choose 0 (instant),
     * 7, 14, or 28 days via the Commerce settings dropdown. Falls back to the
     * system default when no brand-level choice is recorded.
     */
    public function getEffectivePayoutHoldDaysAttribute(): int
    {
        $systemDefault = (int) config('partna.store.payout_hold_days', 7);

        return max(0, $this->payout_hold_days ?? $systemDefault);
    }

    /**
     * Reset all Shopify wizard progress fields.
     * Called on disconnect (dashboard) and app/uninstalled webhook so the
     * setup wizard starts fresh if the brand reconnects.
     */
    public static function clearWizardProgress(string $professionalId): void
    {
        static::where('professional_id', $professionalId)->update([
            'hydrogen_install_confirmed' => false,
            'oxygen_deployment_token' => null,
            'oxygen_storefront_id' => null,
        ]);
    }
}
