<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'billing.plans';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'entitlements' => 'array',
        'is_active' => 'boolean',
    ];
}
