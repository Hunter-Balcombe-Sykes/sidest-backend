<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

// V2: Defines a billing plan with Stripe price mapping, entitlements JSON, and pricing metadata. Read by Subscription to determine active features.
class Plan extends BaseModel
{
    protected $table = 'billing.plans';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
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
