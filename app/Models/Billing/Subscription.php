<?php

namespace App\Models\Billing;

use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $table = 'billing.subscriptions';
    public $incrementing = false;

    protected $casts = [
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'ended_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'provider_payload' => 'array',
    ];

    public function plan(): belongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function professional(): belongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true) && $this->ended_at === null;
    }
}
