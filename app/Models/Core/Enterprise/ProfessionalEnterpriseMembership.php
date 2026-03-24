<?php

namespace App\Models\Core\Enterprise;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalEnterpriseMembership extends BaseModel
{
    use HasUuids;

    protected $table = 'professional_enterprise_memberships';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'enterprise_id',
        'relationship_type',
        'is_primary',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }
}
