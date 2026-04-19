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

    protected $fillable = [
        'shopify_order_id',
        'brand_professional_id',
        'affiliate_professional_id',
        'entry_type',
        'status',
        'amount_cents',
        'currency_code',
        'commission_rate',
        'rate_source',
        'idempotency_key',
        'calculation_metadata',
        'occurred_at',
        'payout_id',
        'voided_at',
        'void_reason',
    ];

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
