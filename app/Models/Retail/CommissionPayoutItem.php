<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Line item linking a commission ledger entry to a payout batch. Records the amount disbursed for each earned commission.
class CommissionPayoutItem extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.commission_payout_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'payout_id',
        'commission_ledger_entry_id',
        'amount_cents',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'created_at' => 'datetime',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'payout_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(CommissionLedgerEntry::class, 'commission_ledger_entry_id');
    }
}
