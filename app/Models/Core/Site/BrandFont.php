<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BrandFont extends BaseModel
{
    use HasUuids, SoftDeletes;

    public const SLOT_PRIMARY = 'primary';
    public const FORMAT_WOFF2 = 'woff2';

    protected $table = 'brand_fonts';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'slot',
        'file_name',
        'file_path',
        'file_url',
        'format',
        'file_hash',
        'size_bytes',
        'is_active',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }
}
