<?php

namespace App\Models\Analytics;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Tracks cart add and checkout start events from Hydrogen storefronts. Feeds the shop analytics funnel alongside site_visits and commission_ledger_entries.
class CartEvent extends BaseModel
{
    use HasUuids;

    protected $table = 'analytics.cart_events';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'occurred_at',
        'session_id',
        'visitor_id',
        'ip_hash',
        'shopify_product_id',
        'quantity',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
