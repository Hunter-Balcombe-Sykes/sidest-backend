<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandProductMedia extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.brand_product_media';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'brand_product_id',
        'professional_id',
        'site_media_id',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandProduct(): BelongsTo
    {
        return $this->belongsTo(BrandProduct::class, 'brand_product_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function siteMedia(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'site_media_id');
    }
}
