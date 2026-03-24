<?php

namespace App\Models\Core\Enterprise;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseBrandLink extends BaseModel
{
    use HasUuids;

    protected $table = 'enterprise_brand_links';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'enterprise_id',
        'brand_professional_id',
        'role',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }
}
