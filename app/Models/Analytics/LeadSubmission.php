<?php

namespace App\Models\Analytics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class LeadSubmission extends Model
{
    use HasUuids;

    protected $table = 'analytics.lead_submissions';

    public $incrementing = false;
    protected $keyType = 'string';

    // analytics tables don't have updated_at
    public $timestamps = false;

    protected $fillable = [
        'occurred_at',
        'subdomain',
        'site_id',
        'professional_id',
        'customer_id',
        'ip_hash',
        'user_agent',
        'referrer',
        'outcome',
        'form_started_at_ms',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
