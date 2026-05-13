<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use App\Models\Retail\CommissionPayout;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Records Stripe Transfer Reversals issued when a Shopify refund arrives AFTER a
// CommissionPayout has settled (status='completed'). One row per (payout, order)
// pair — uniqueness enforced at the DB layer so duplicate refund webhooks for the
// same order cannot create a second reversal.
//
// status:
//   'reversed'           — Stripe Transfer Reversal succeeded; funds clawed back
//   'reversal_failed'    — Stripe rejected the reversal (often insufficient affiliate
//                          balance); flagged for manual recovery via the ops queue
//   'manual_recovered'   — ops manually reconciled the refund without a Stripe reversal
class CommissionClawback extends BaseModel
{
    use HasFactory;
    use HasUuids;

    protected $table = 'commerce.commission_clawbacks';

    public $incrementing = false;

    protected $keyType = 'string';

    // All writes are server-side via app_backend (BYPASSRLS). Use forceFill() at callsites.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
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
