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
        'domain_mode',
        'domain_wizard_complete',
        'custom_domain',
        'custom_domain_verified_at',
        'custom_domain_tls_provisioned_at',
        'hydrogen_install_confirmed',
        'domain_txt_confirmed',
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
        'custom_domain_verified_at' => 'datetime',
        'custom_domain_tls_provisioned_at' => 'datetime',
    ];

    /**
     * Compute the public storefront base URL for this brand.
     *
     * Returns the custom domain when fully provisioned (verified + TLS),
     * otherwise falls back to the platform subdomain URL. The $subdomain
     * parameter is the brand's site.subdomain — passed in because this model
     * doesn't own a direct relationship to Site.
     */
    public function storefrontBaseUrl(string $subdomain): string
    {
        if ($this->domain_mode === 'custom'
            && $this->custom_domain
            && $this->custom_domain_tls_provisioned_at) {
            return 'https://' . $this->custom_domain;
        }

        return 'https://' . $subdomain . '.sidest.co';
    }

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

    /**
     * Reset all Shopify wizard progress fields.
     * Called on disconnect (dashboard) and app/uninstalled webhook so the
     * setup wizard starts fresh if the brand reconnects.
     */
    public static function clearWizardProgress(string $professionalId): void
    {
        static::where('professional_id', $professionalId)->update([
            'hydrogen_install_confirmed'       => false,
            'oxygen_deployment_token'          => null,
            'oxygen_storefront_id'             => null,
            'domain_wizard_complete'           => false,
            'domain_txt_confirmed'             => false,
            'domain_mode'                      => 'platform',
            'custom_domain'                    => null,
            'custom_domain_verified_at'        => null,
            'custom_domain_tls_provisioned_at' => null,
        ]);
    }
}
