<?php

namespace App\Models\Analytics;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteVisit extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'analytics.site_visits';

    public $incrementing = false;
    protected $keyType = 'string';

    // analytics tables don't have updated_at
    public $timestamps = false;

    protected $fillable = [
        'occurred_at',
        'session_id',
        'visitor_id',
        'ip_hash',
        'user_agent',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'country_code',
        'device_type',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
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
