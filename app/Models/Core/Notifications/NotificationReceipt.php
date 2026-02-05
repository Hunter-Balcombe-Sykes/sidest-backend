<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Core\Professional\Professional;

class NotificationReceipt extends BaseModel
{
    use HasUuids;

    protected $table = 'core.notification_receipts';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'notification_id',
        'professional_id',
        'read_at',
        'dismissed_at',
    ];

    protected $casts = [
        'read_at'      => 'datetime',
        'dismissed_at' => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }
}
