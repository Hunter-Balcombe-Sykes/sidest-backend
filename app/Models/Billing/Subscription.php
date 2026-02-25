<?php

namespace App\Models\Billing;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends BaseModel
{
    protected $table = 'subscriptions';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'professional_id',
        'plan_id',
        'provider',
        'stripe_customer_id',
        'stripe_subscription_id',
        'status',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'trial_ends_at',
        'ended_at',
        'provider_payload',
    ];

    protected $casts = [
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'ended_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'provider_payload' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true) && $this->ended_at === null;
    }
}
