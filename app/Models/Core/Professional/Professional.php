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

        // Images
        'icon_bucket',
        'icon_path',
        'headshot_bucket',
        'headshot_path',

        // Location
        'location_street_address',
        'location_city',
        'location_state',
        'location_postcode',
        'location_country',

        'handle_lc',

        // Square integration
        'square_access_token',
        'square_refresh_token',
        'square_merchant_id',
        'square_expires_at',
        'square_catalog_latest_time',
        'square_last_catalog_sync_at',
        'square_last_catalog_sync_error',

        // Fresha integration
        'fresha_access_token',
        'fresha_refresh_token',
        'fresha_business_id',
        'fresha_expires_at',
        'fresha_catalog_latest_time',
        'fresha_last_catalog_sync_at',
        'fresha_last_catalog_sync_error',
    ];

    protected $casts = [
        'onboarding_step' => 'integer',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
        'deleted_at'      => 'datetime',
        'square_access_token'  => 'encrypted',
        'square_refresh_token' => 'encrypted',
        'square_expires_at'    => 'datetime',
        'square_catalog_latest_time' => 'datetime',
        'square_last_catalog_sync_at' => 'datetime',
        'fresha_access_token'  => 'encrypted',
        'fresha_refresh_token' => 'encrypted',
        'fresha_expires_at'    => 'datetime',
        'fresha_catalog_latest_time' => 'datetime',
        'fresha_last_catalog_sync_at' => 'datetime',
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
            ->where('block_type', 'section')
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
