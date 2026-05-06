<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Commerce\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Line item linking a payout batch to a commerce.orders row via order_id.
// `order_id` is NOT NULL post-Phase-4 (migration 20260506500000); the legacy
// `commission_ledger_entry_id` link target was dropped in the same migration.
class CommissionPayoutItem extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.commission_payout_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'payout_id',
        'order_id',
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
