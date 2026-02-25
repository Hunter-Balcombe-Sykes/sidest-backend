<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

class Plan extends BaseModel
{
    protected $table = 'plans';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'plan_key',
        'name',
        'description',
        'stripe_price_id',
        'is_active',
        'sort_order',
        'price_cents',
        'currency_code',
        'billing_interval',
        'entitlements',
    ];

    protected $casts = [
        'entitlements' => 'array',
        'is_active' => 'boolean',
        'price_cents' => 'integer',
    ];
}
