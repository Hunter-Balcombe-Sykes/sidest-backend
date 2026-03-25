<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'customers';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $hidden = [
        'external_id',
    ];

    protected $fillable = [
        'email',
        'phone',
        'full_name',
        'source',
        'notes',
        'external_id',
        'marketing_opt_in_cached',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'marketing_opt_in_cached' => 'boolean',
    ];

    /**
     * Get the current marketing opt-in status from EmailSubscription (source of truth).
     * Falls back to cache if available.
     */
    public function isMarketingOptedIn(): bool
    {
        $subscription = \App\Models\Core\Notifications\EmailSubscription::query()
            ->where('professional_id', $this->professional_id)
            ->where('list_key', 'marketing')
            ->where('email_lc', strtolower($this->email ?? ''))
            ->first();

        return $subscription?->status === 'subscribed' ?? ($this->marketing_opt_in_cached ?? false);
    }

    /**
     * Sync cache from EmailSubscription status (call when subscription changes).
     */
    public function syncMarketingOptInCache(): void
    {
        if (empty($this->email)) {
            $this->marketing_opt_in_cached = null;
            return;
        }

        $subscription = \App\Models\Core\Notifications\EmailSubscription::query()
            ->where('professional_id', $this->professional_id)
            ->where('list_key', 'marketing')
            ->where('email_lc', strtolower($this->email))
            ->first();

        $this->marketing_opt_in_cached = $subscription?->status === 'subscribed';
        $this->save();
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }
}
