<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: A bookable service offered by a professional. Stores pricing, duration, and optional Square/Fresha sync metadata for POS integration.
class Service extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.services';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'title',
        'category_id',
        'description',
        'price_cents',
        'currency_code',
        'duration_minutes',
        'is_active',
        'sort_order',

        // Square integration
        'square_catalog_object_id',
        'square_variation_id',
        'square_catalog_version',
        'square_last_synced_at',
        'square_sync_error',

        // Deletion origin: 'square' = sync-deleted (restorable); null = manually deleted (never auto-restore)
        'deleted_origin',

        // Fresha integration
        'fresha_service_id',
        'fresha_variation_id',
        'fresha_service_version',
        'fresha_last_synced_at',
        'fresha_sync_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_cents' => 'integer',
        'sort_order' => 'integer',
        'duration_minutes' => 'integer',
        'deleted_at' => 'datetime',
        'square_catalog_version' => 'integer',
        'square_last_synced_at' => 'datetime',
        'fresha_service_version' => 'integer',
        'fresha_last_synced_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }
}
