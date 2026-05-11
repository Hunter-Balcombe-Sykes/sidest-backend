<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Database\Factories\CommissionPayoutFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// V2: Core. Tracks payout lifecycle (pending → processing → completed/failed). Links brand, affiliate, Stripe transfer, and funding details.
class CommissionPayout extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'commerce.commission_payouts';

    public $incrementing = false;

    protected $keyType = 'string';

    // All writes are server-side (CommissionPayoutService). Use forceFill() at callsites.
    protected $guarded = ['*'];

    protected $casts = [
        'gross_commission_cents' => 'integer',
        'platform_fee_cents' => 'integer',
        'net_payout_cents' => 'integer',
        'wallet_debit_cents' => 'integer',
        'charge_cents' => 'integer',
        'ledger_entry_count' => 'integer',
        'retry_count' => 'integer',
        'needs_manual_refund' => 'boolean',
        'eligible_after' => 'datetime',
        'processed_at' => 'datetime',
        'void_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',

        // Lifecycle columns added 2026-05-10
        'transfer_completed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'last_retry_at' => 'datetime',
        'funding_failure_count' => 'integer',
        'grace_notifications_sent' => 'array',

        // #STRIPE-4: warning-clock anchor, stamped once on first markPendingFunding.
        // Decoupled from void_at (which resets every retry for retry-safety).
        'grace_started_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommissionPayoutItem::class, 'payout_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CommissionMovement::class, 'payout_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    protected static function newFactory(): CommissionPayoutFactory
    {
        return CommissionPayoutFactory::new();
    }
}
