<?php

namespace App\Models\Billing;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Stripe-backed subscription linking a professional to a plan. Tracks billing period, cancellation state, and raw provider payload.
class Subscription extends BaseModel
{
    protected $table = 'billing.subscriptions';

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_INCOMPLETE_EXPIRED = 'incomplete_expired';

    public const GRACE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_PAST_DUE];

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

    protected $hidden = [
        'stripe_customer_id',
        'stripe_subscription_id',
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
        return $this->status === self::STATUS_ACTIVE && $this->ended_at === null;
    }

    public function isInGracePeriod(): bool
    {
        return in_array($this->status, self::GRACE_STATUSES, true) && $this->ended_at === null;
    }

    public function isStripeManaged(): bool
    {
        return $this->provider === 'stripe';
    }

    public function isFreeInternal(): bool
    {
        return $this->provider === 'internal';
    }
}
