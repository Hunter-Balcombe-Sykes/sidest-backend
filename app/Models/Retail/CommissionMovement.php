<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Forward-looking model for the post-rename world. Currently points at the unrenamed
// commerce.commission_ledger_entries — Phase 4 cleanup renames the table to
// commerce.commission_movements and updates this $table value in the same PR.
//
// Use this class for NEW code (Phase 3 webhook rewrites) that writes only money-movement
// rows. Legacy callers continue using App\Models\Retail\CommissionLedgerEntry until
// Phase 4. Both models read/write the same table.
//
// Scope (post-rename) is MONEY MOVEMENTS ONLY:
//   - entry_type='payout'     — payout settled
//   - entry_type='clawback'   — post-payout reversal
//   - entry_type='adjustment' — manual support correction
//
// Order-lifecycle state (accruals, reversals from refunds) lives on commerce.orders +
// commerce.order_events. Pre-cutover accrual/reversal rows are dropped in Phase 4 cleanup.
class CommissionMovement extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.commission_ledger_entries';  // → commission_movements in Phase 4

    public $incrementing = false;

    protected $keyType = 'string';

    // All writes server-side (CommissionPayoutService, manual adjustments). Use forceFill().
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
