<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

// V2: Marketing email opt-in/out record per professional+email. Source of truth for consent; caches status on Customer for performance.
// status ('subscribed'/'unsubscribed') is enforced at the DB level. @see supabase/migrations/202605190000002_add_enum_check_constraints.sql
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

        if (isset($meta['source'])) {
            $this->consent_source = $meta['source'];
        }
        if (isset($meta['ip_hash'])) {
            $this->consent_ip_hash = $meta['ip_hash'];
        }
        if (isset($meta['user_agent'])) {
            $this->consent_user_agent = $meta['user_agent'];
        }
    }

    public function markUnsubscribed(): void
    {
        $this->status = 'unsubscribed';
        $this->unsubscribed_at = now();
    }

    /**
     * Sync customer cache after save (when status changes).
     * Source of truth is this EmailSubscription.status, but we cache on Customer for UX/perf.
     * Dispatched async (CACHE-11) so bulk subscribe imports don't pay a per-row Customer lookup
     * inside the originating request — Customer.isMarketingOptedIn() falls back to a live
     * read while the cache catches up.
     */
    protected static function booted(): void
    {
        static::saved(function (self $subscription) {
            if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                    (string) $subscription->professional_id,
                    (string) $subscription->email,
                    $subscription->status === 'subscribed',
                );
            }
        });
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }
}
