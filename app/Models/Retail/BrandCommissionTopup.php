<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Manual wallet top-up record via Stripe Checkout. Credits brand's commission wallet balance.
class BrandCommissionTopup extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.brand_commission_topups';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'amount_cents',
        'currency_code',
        'status',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }
}
