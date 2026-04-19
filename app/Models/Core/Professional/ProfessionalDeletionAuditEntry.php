<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Audit trail for professional account deletion lifecycle. Rows survive the
// professional's hard delete via handle/email snapshots captured at write time.
class ProfessionalDeletionAuditEntry extends BaseModel
{
    use HasUuids;

    protected $table = 'core.professional_deletion_audit';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // only created_at; no updated_at

    public const EVENT_REQUESTED = 'requested';

    public const EVENT_CONFIRMED = 'confirmed';

    public const EVENT_CANCELLED = 'cancelled';

    public const EVENT_PURGED = 'purged';

    public const EVENT_PURGE_FAILED = 'purge_failed';

    protected $fillable = [
        'professional_id',
        'professional_handle_snapshot',
        'professional_email_snapshot',
        'event',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
