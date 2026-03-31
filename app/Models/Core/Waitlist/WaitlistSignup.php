<?php

namespace App\Models\Core\Waitlist;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WaitlistSignup extends BaseModel
{
    use HasUuids;

    protected $table = 'waitlist_signups';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $hidden = [
        'consent_ip_hash',
        'consent_user_agent',
    ];

    protected $fillable = [
        'name',
        'email',
        'email_lc',
        'phone',
        'applicant_type',
        'applicant_type_other',
        'industry',
        'industry_other',
        'pilot_program_opt_in',
        'number_of_team_members',
        'number_of_affiliates_ambassadors',
        'is_brand_partner_or_ambassador',
        'currently_sells_products',
        'consent_source',
        'consent_ip_hash',
        'consent_user_agent',
        'last_submitted_at',
    ];

    protected $casts = [
        'pilot_program_opt_in' => 'boolean',
        'is_brand_partner_or_ambassador' => 'boolean',
        'currently_sells_products' => 'boolean',
        'number_of_team_members' => 'integer',
        'number_of_affiliates_ambassadors' => 'integer',
        'last_submitted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
