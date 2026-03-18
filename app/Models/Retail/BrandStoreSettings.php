<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'default_commission_rate' => 'decimal:2',
    ];
}
