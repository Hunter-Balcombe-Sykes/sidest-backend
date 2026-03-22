<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderEventInbox extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.order_event_inbox';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'source',
        'external_event_id',
        'event_type',
        'shop_domain',
        'integration_id',
        'brand_professional_id',
        'payload',
        'headers',
        'status',
        'attempts',
        'received_at',
        'processed_at',
        'rejection_reason',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'attempts' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ProfessionalIntegration::class, 'integration_id');
    }

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }
}
