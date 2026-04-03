<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class NotificationEmailPreference extends BaseModel
{
    use HasUuids;

    protected $table = 'notifications.notification_email_preferences';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'category_key',
        'enabled',
    ];

    protected $casts = [
        'enabled'    => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
