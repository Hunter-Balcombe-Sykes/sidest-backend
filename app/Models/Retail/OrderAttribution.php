<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAttribution extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.order_attributions';

    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'model',
        'model_version',
        'reason',
        'lineage',
        'created_at',
    ];

    protected $casts = [
        'lineage' => 'array',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(RetailOrder::class, 'order_id');
    }
}
