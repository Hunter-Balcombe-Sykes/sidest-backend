<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// V2: Core. Tracks payout lifecycle (pending → processing → completed/failed). Links brand, affiliate, Stripe transfer, and funding details.
class CommissionPayout extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.commission_payouts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'affiliate_professional_id',
        'stripe_payment_intent_id',
        'stripe_transfer_id',
        'status',
        'gross_commission_cents',
        'platform_fee_cents',
        'net_payout_cents',
        'currency_code',
        'failure_reason',
        'failure_code',
        'ledger_entry_count',
        'eligible_after',
        'processed_at',
        'funding_source',
        'wallet_debit_cents',
        'charge_cents',
        'retry_count',
        // Per-payout grace deadline (created_at + 60d by default). When
        // void_at passes and the affiliate's Stripe Connect isn't active,
        // the nightly VoidExpiredPayoutsJob marks the payout `cancelled`
        // and links its ledger entries `voided`.
        'void_at',
        // Stripe `fee_xxx` reference when the payout used the destination-
        // charge + application_fee path (R4 hybrid). Null for wallet-only
        // payouts that took the manual Transfer path.
        'stripe_application_fee_id',
    ];

    protected $casts = [
        'gross_commission_cents' => 'integer',
        'platform_fee_cents' => 'integer',
        'net_payout_cents' => 'integer',
        'wallet_debit_cents' => 'integer',
        'charge_cents' => 'integer',
        'ledger_entry_count' => 'integer',
        'retry_count' => 'integer',
        'eligible_after' => 'datetime',
        'processed_at' => 'datetime',
        'void_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
        return $this->hasMany(CommissionLedgerEntry::class, 'payout_id');
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
}
