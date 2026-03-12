<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Enterprise\Enterprise;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseBrand extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.enterprise_brands';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'enterprise_id',
        'name',
        'slug',
        'description',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
        return $this->hasMany(EnterpriseProduct::class, 'brand_id');
    }
}
