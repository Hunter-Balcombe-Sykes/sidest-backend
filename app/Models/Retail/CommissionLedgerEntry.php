<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// DEPRECATED: order-lifecycle accrual/reversal rows move to App\Models\Commerce\Order
// in the Phase 3 webhook rewrite. Money-movement rows (payouts/clawbacks/adjustments)
// move to App\Models\Retail\CommissionMovement when the table is renamed in Phase 4.
// Until then this class continues to serve all current webhook job callers unchanged.
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
