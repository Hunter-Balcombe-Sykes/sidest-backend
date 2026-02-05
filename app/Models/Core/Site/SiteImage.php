<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteImage extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'core.site_images';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'site_id',
        'bucket',
        'path',
        'alt_text',
        'sort_order',
        'is_active'
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
