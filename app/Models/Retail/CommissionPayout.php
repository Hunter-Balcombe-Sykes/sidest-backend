<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionPayout extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.commission_payouts';

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
    ];

    protected $casts = [
        'gross_commission_cents' => 'integer',
        'platform_fee_cents' => 'integer',
        'net_payout_cents' => 'integer',
        'wallet_debit_cents' => 'integer',
        'charge_cents' => 'integer',
        'ledger_entry_count' => 'integer',
        'eligible_after' => 'datetime',
        'processed_at' => 'datetime',
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
