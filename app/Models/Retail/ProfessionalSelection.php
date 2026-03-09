<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalSelection extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.professional_selections';

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'professional_id',
        'shopify_product_id',
        'sort_order',
        'commission_override',
    ];

    protected $casts = [
        'sort_order'            => 'integer',
        'commission_override'   => 'decimal:5,2',
        'created_at'            => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
