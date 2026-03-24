<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Enterprise\Enterprise;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseShopifyAccount extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.enterprise_shopify_accounts';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'enterprise_id',
        'shop_domain',
        'shop_name',
        'external_shop_id',
        'token_reference',
        'is_primary',
        'is_active',
        'connected_at',
        'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'connected_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(EnterpriseProduct::class, 'shopify_account_id');
    }
}
