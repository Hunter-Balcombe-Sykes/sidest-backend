<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionLedgerEntry extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.commission_ledger_entries';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'brand_professional_id',
        'affiliate_professional_id',
        'payout_run_id',
        'entry_type',
        'status',
        'amount_cents',
        'currency_code',
        'commission_rate',
        'rate_source',
        'idempotency_key',
        'calculation_metadata',
        'occurred_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'commission_rate' => 'float',
        'calculation_metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(RetailOrder::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }

    public function payoutRun(): BelongsTo
    {
        return $this->belongsTo(PayoutRun::class, 'payout_run_id');
    }
}
