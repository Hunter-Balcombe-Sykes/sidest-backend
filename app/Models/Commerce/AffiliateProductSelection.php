<?php

namespace App\Models\Commerce;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: New. Affiliate's selected products using shopify_product_gid (not local UUID). Replaces V1 professional_selections table.
class AffiliateProductSelection extends BaseModel
{
    use HasUuids;

    protected $table = 'commerce.affiliate_product_selections';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'affiliate_professional_id',
        'brand_professional_id',
        'shopify_product_gid',
        'sort_order',
        'selected_variant_gids',
    ];

    // selected_variant_gids: NULL = show all brand-enabled variants (default);
    // non-null array = only these variant GIDs, still intersected with the
    // brand's sidest.enabled variant metafield at read time.
    protected $casts = [
        'sort_order' => 'integer',
        'selected_variant_gids' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function affiliateProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'affiliate_professional_id');
    }

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }
}
