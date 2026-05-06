<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Trigger-maintained incremental rollup of (day, brand, affiliate, currency).
// `day` is UTC; brand-local timezone display happens in the read controller.
//
// Maintained by:
//   - trg_rollup on commerce.orders          (per-order signed deltas)
//   - trg_rollup_clawback on commission_movements (post-payout clawback deltas)
//
// Read by analytics controllers via the brand dashboard's per-affiliate breakdown query.
// Composite primary key — no auto-increment id.
class BrandAffiliateRollup extends BaseModel
{
    protected $table = 'commerce.brand_affiliate_rollup';

    // Composite primary key — Eloquent doesn't natively support, so disable id-based finds
    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'day' => 'date',
        'orders_count' => 'integer',
        'gross_cents' => 'integer',
        'refund_cents' => 'integer',
        'net_cents' => 'integer',
        'commission_cents' => 'integer',
        'reversed_commission_cents' => 'integer',
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
}
