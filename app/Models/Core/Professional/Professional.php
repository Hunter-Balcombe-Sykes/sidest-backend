<?php

namespace App\Models\Core\Professional;

use App\Models\Analytics\LinkClick;
use App\Models\Analytics\SiteVisit;
use App\Models\BaseModel;
use App\Models\Billing\Subscription;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;
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

    protected $fillable = [
        'handle',
        'display_name',
        'bio',
        'country_code',
        'timezone',
        'status',
        'onboarding_step',
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

    ];

    protected $casts = [
        'onboarding_step' => 'integer',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
        'deleted_at'      => 'datetime',
    ];

    public function site(): HasOne
    {
        return $this->hasOne(Site::class, 'professional_id');
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
