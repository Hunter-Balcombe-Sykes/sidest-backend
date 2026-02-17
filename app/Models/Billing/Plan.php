<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

class Plan extends BaseModel
{
    protected $table = 'plans';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'entitlements' => 'array',
        'is_active' => 'boolean',
    ];
}
