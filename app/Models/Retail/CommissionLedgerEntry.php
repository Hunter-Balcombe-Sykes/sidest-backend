<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Core. Records commission per order line from Shopify orders/paid webhook. Tracks entry_type, status (pending/approved/reversed), and rate_source.
class CommissionLedgerEntry extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.commission_ledger_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    // All writes are server-side (Shopify order jobs, CommissionPayoutService). Use forceFill() at callsites.
    protected $guarded = ['*'];

    protected $casts = [
        'amount_cents' => 'integer',
        'commission_rate' => 'float',
        'calculation_metadata' => 'array',
        'occurred_at' => 'datetime',
        'voided_at' => 'datetime',
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

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayout::class, 'payout_id');
    }
}
