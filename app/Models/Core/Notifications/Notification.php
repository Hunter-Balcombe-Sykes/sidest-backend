<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Core\Professional\Professional;

class Notification extends BaseModel
{
    use HasUuids;

    protected $table = 'core.notifications';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'type',
        'title',
        'body',
        'cta_url',
        'severity',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(NotificationReceipt::class, 'notification_id');
    }

    public function scopeVisibleTo(Builder $query, Professional $professional): Builder
    {
        $now = now();

        return $query
            ->where(function (Builder $q) use ($professional): void {
                $q->whereNull('professional_id')
                    ->orWhere('professional_id', $professional->id);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }
}
