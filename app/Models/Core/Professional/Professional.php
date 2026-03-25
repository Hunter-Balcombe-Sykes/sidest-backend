<?php

namespace App\Models\Core\Professional;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\BaseModel;
use App\Models\Billing\Subscription;
use App\Models\Core\Enterprise\Enterprise;
use App\Models\Core\Enterprise\InfluencerPromoterContract;
use App\Models\Core\Enterprise\ProfessionalEnterpriseMembership;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $auth_user_id
 * @property string $handle
 * @property string $display_name
 * @property int $onboarding_step
 */

class Professional extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'professionals';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $hidden = [
        'auth_user_id',
        'stripe_connect_account_id',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'stripe_commission_funding_mode',
        'stripe_manual_balance_cents',
        'stripe_manual_balance_currency',
    ];

    protected $fillable = [
        'handle',
        'display_name',
        'bio',
        'country_code',
        'timezone',
        'professional_type',
        'status',
        'onboarding_step',
        'primary_enterprise_id',
        'qr_slug',
        'phone',
        'primary_email',
        'first_name',
        'last_name',

        // Public Accessible Contacts
        'public_contact_number',
        'public_contact_email',

        // Location
        'location_street_address',
        'location_city',
        'location_state',
        'location_postcode',
        'location_country',

        'handle_lc',

        // Stripe Connect + commission funding
        'stripe_connect_account_id',
        'stripe_connect_status',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'stripe_commission_funding_mode',
        'stripe_manual_balance_cents',
        'stripe_manual_balance_currency',

    ];

    protected $casts = [
        'onboarding_step' => 'integer',
        'stripe_manual_balance_cents' => 'integer',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
        'deleted_at'      => 'datetime',
    ];

    public function isInfluencer(): bool
    {
        return mb_strtolower(trim((string) ($this->professional_type ?? ''))) === 'influencer';
    }

    public function isBrand(): bool
    {
        return mb_strtolower(trim((string) ($this->professional_type ?? ''))) === 'brand';
    }

    public function isProfessional(): bool
    {
        return mb_strtolower(trim((string) ($this->professional_type ?? ''))) === 'professional';
    }

    public function brandProfile(): HasOne
    {
        return $this->hasOne(BrandProfile::class, 'professional_id');
    }

    public function site(): HasOne
    {
        return $this->hasOne(Site::class, 'professional_id');
    }

    public function primaryEnterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'primary_enterprise_id');
    }

    public function enterpriseMemberships(): HasMany
    {
        return $this->hasMany(ProfessionalEnterpriseMembership::class, 'professional_id')
            ->orderByDesc('starts_at');
    }

    public function influencerPromoterContracts(): HasMany
    {
        return $this->hasMany(InfluencerPromoterContract::class, 'influencer_professional_id')
            ->orderByDesc('starts_at');
    }

    public function activeInfluencerPromoterContract(): HasOne
    {
        return $this->hasOne(InfluencerPromoterContract::class, 'influencer_professional_id')
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest('starts_at');
    }

    public function legalContent(): HasOne
    {
        return $this->hasOne(ProfessionalLegalContent::class, 'professional_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'professional_id');
    }

    public function linkBlocks(): HasMany
    {
        return $this->blocks()
            ->where('block_group', 'links')
            ->where('block_type', 'link')
            ->orderBy('sort_order');
    }

    public function sectionBlocks(): HasMany
    {
        return $this->blocks()
            ->where('block_group', 'sections')
            ->orderBy('sort_order');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'professional_id')
            ->orderByDesc('created_at');
    }

    public function siteVisits(): HasMany
    {
        return $this->hasMany(SiteVisit::class, 'professional_id');
    }

    public function linkClicks(): HasMany
    {
        return $this->hasMany(LinkClick::class, 'professional_id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'professional_id');
    }

    public function serviceCategories()
    {
        return $this->hasMany(ServiceCategory::class);
    }

    public function emailSubscriptions(): HasMany
    {
        return $this->hasMany(EmailSubscription::class, 'professional_id');
    }

    public function brandAffiliateInvites(): HasMany
    {
        return $this->hasMany(BrandAffiliateInvite::class, 'brand_professional_id')
            ->orderByDesc('created_at');
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'professional_id');
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(ProfessionalIntegration::class, 'professional_id');
    }

    public function squareIntegration(): HasOne
    {
        return $this->hasOne(ProfessionalIntegration::class, 'professional_id')
            ->where('provider', ProfessionalIntegration::PROVIDER_SQUARE);
    }

    public function freshaIntegration(): HasOne
    {
        return $this->hasOne(ProfessionalIntegration::class, 'professional_id')
            ->where('provider', ProfessionalIntegration::PROVIDER_FRESHA);
    }

    public function shopifyIntegration(): HasOne
    {
        return $this->hasOne(ProfessionalIntegration::class, 'professional_id')
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY);
    }

    public function productSelections(): HasMany
    {
        return $this->hasMany(\App\Models\Retail\ProfessionalSelection::class, 'professional_id')
            ->orderBy('sort_order');
    }

    public function integrationForProvider(string $provider): ?ProfessionalIntegration
    {
        $provider = mb_strtolower(trim($provider));

        if ($provider === '') {
            return null;
        }

        if ($this->relationLoaded('integrations')) {
            return $this->integrations->firstWhere('provider', $provider);
        }

        return $this->integrations()->where('provider', $provider)->first();
    }

    public function resolveChildRouteBindingQuery($childType, $value, $field): Builder
    {
        $query = parent::resolveChildRouteBindingQuery($childType, $value, $field);

        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }

        // Staff endpoints need to bind trashed customers/services for restore/hard-delete
        if (in_array($childType, [Customer::class, Service::class], true)) {
            $query->withTrashed();
        }

        return $query;
    }

}
