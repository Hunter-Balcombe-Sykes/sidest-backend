<?php

namespace App\Models\Core\Enterprise;

use App\Models\BaseModel;
use App\Models\Retail\EnterpriseBrand;
use App\Models\Retail\EnterpriseProduct;
use App\Models\Retail\EnterpriseShopifyAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enterprise extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'enterprises';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'auth_user_id',
    ];

    protected $fillable = [
        'auth_user_id',
        'name',
        'handle',
        'primary_email',
        'phone',
        'public_contact_email',
        'public_contact_number',
        'country_code',
        'timezone',
        'location_street_address',
        'location_city',
        'location_state',
        'location_postcode',
        'location_country',
        'enterprise_type',
        'status',
        'subscription_tier',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(ProfessionalEnterpriseMembership::class, 'enterprise_id');
    }

    public function promoterContracts(): HasMany
    {
        return $this->hasMany(InfluencerPromoterContract::class, 'promoter_enterprise_id');
    }

    public function shopifyAccounts(): HasMany
    {
        return $this->hasMany(EnterpriseShopifyAccount::class, 'enterprise_id');
    }

    public function brands(): HasMany
    {
        return $this->hasMany(EnterpriseBrand::class, 'enterprise_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(EnterpriseProduct::class, 'enterprise_id');
    }

    public function brandLinks(): HasMany
    {
        return $this->hasMany(EnterpriseBrandLink::class, 'enterprise_id');
    }

    public function managedBrands(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Core\Professional\Professional::class,
            'enterprise_brand_links',
            'enterprise_id',
            'brand_professional_id'
        )
            ->withPivot(['id', 'role', 'status', 'created_at', 'updated_at'])
            ->wherePivot('status', 'active');
    }
}
