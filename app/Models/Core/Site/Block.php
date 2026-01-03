<?php

namespace App\Models\Core\Site;

use App\Models\Analytics\LinkClick;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Block extends Model
{
    use HasUuids;

    /**
     * @var false|mixed
     */
    protected $table = 'core.blocks';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'site_id',
        'block_type',
        'block_group',
        'title',
        'url',
        'icon_key',
        'sort_order',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
        'settings'   => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(LinkClick::class, 'block_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
