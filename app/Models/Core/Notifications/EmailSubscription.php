<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailSubscription extends BaseModel
{
    use HasUuids;

    protected $table = 'notifications.email_subscriptions';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $hidden = [
        'unsubscribe_token',
        'consent_ip_hash',
        'consent_user_agent',
    ];

    protected $fillable = [
        'professional_id',
        'list_key',
        'email',
        'full_name',
        'status',
        'subscribed_at',
        'unsubscribed_at',
        'unsubscribe_token',
        'consent_source',
        'consent_ip_hash',
        'consent_user_agent',
        'email_lc',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function newUnsubscribeToken(): string
    {
        return Str::random(48);
    }

    public function markSubscribed(array $meta = []): void
    {
        $this->status = 'subscribed';
        $this->subscribed_at = $this->subscribed_at ?? now();
        $this->unsubscribed_at = null;

        if (isset($meta['source'])) $this->consent_source = $meta['source'];
        if (isset($meta['ip_hash'])) $this->consent_ip_hash = $meta['ip_hash'];
        if (isset($meta['user_agent'])) $this->consent_user_agent = $meta['user_agent'];
    }

    public function markUnsubscribed(): void
    {
        $this->status = 'unsubscribed';
        $this->unsubscribed_at = now();
    }

    /**
     * Sync customer cache after save (when status changes).
     * Source of truth is this EmailSubscription.status, but we cache on Customer for UX/perf.
     */
    protected static function booted(): void
    {
        static::saved(function (self $subscription) {
            if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                $customer = \App\Models\Core\Professional\Customer::query()
                    ->where('professional_id', $subscription->professional_id)
                    ->where('email', $subscription->email)
                    ->first();

                if ($customer) {
                    $customer->marketing_opt_in_cached = $subscription->status === 'subscribed';
                    $customer->saveQuietly();
                }
            }
        });
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }
}
