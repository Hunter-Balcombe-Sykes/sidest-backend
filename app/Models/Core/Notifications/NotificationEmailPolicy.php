<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class NotificationEmailPolicy extends BaseModel
{
    use HasUuids;

    protected $table = 'notifications.notification_email_policies';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'category_key',
        'mode',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
