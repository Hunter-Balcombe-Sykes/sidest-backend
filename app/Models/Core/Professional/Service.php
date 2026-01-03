<?php

namespace App\Models\Core\Professional;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'core.services';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'title',
        'category',
        'description',
        'price_cents',
        'currency_code',
        'duration_minutes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_cents' => 'integer',
        'sort_order' => 'integer',
        'duration_minutes' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
