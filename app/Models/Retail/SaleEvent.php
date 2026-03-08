<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleEvent extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.sale_events';

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'professional_id',
        'shopify_product_id',
        'shopify_order_id',
        'quantity',
        'sale_amount_cents',
        'currency_code',
        'event_payload',
    ];

    protected $casts = [
        'quantity'         => 'integer',
        'sale_amount_cents' => 'integer',
        'event_payload'    => 'array',
        'recorded_at'      => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
