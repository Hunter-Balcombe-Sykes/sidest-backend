<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Money-movement ledger. Scope is enforced at the DB level post-Phase-4 (accrual and
// reversal rows were deleted by 20260506500000_drop_legacy_aggregates.sql; the table
// itself was renamed by 20260506600000_rename_ledger_to_movements.sql):
//   - entry_type='payout'     — payout settled
//   - entry_type='clawback'   — post-payout reversal
//   - entry_type='adjustment' — manual support correction
//
// Order-lifecycle state (accruals, reversals from refunds) lives on commerce.orders +
// commerce.order_events. brand_affiliate_rollup carries the per-day signed deltas.
class CommissionMovement extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.commission_movements';

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
