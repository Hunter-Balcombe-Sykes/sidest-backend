<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Enterprise\Enterprise;
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
        'brand_professional_id',
        'brand_product_id',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'commission_override' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class, 'enterprise_id');
    }

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    public function brandProduct(): BelongsTo
    {
        return $this->belongsTo(BrandProduct::class, 'brand_product_id');
    }
}
