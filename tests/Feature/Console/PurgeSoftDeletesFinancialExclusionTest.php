<?php

use App\Models\Commerce\Order;
use App\Models\Retail\CommissionMovement;
use App\Models\Retail\CommissionPayout;
use App\Models\Retail\CommissionPayoutItem;

// Audit test: confirms that financial models have no SoftDeletes trait.
// The purge command (partna:purge-soft-deletes) only touches Customer/Service/SiteMedia;
// these models must never acquire SoftDeletes — a deleted financial record would be an
// AUSTRAC/audit-log gap.

it('CommissionPayout has no SoftDeletes trait', function () {
    $uses = class_uses_recursive(CommissionPayout::class);
    expect($uses)->not->toHaveKey(\Illuminate\Database\Eloquent\SoftDeletes::class);
});

it('Order has no SoftDeletes trait', function () {
    $uses = class_uses_recursive(Order::class);
    expect($uses)->not->toHaveKey(\Illuminate\Database\Eloquent\SoftDeletes::class);
});

it('CommissionMovement has no SoftDeletes trait', function () {
    $uses = class_uses_recursive(CommissionMovement::class);
    expect($uses)->not->toHaveKey(\Illuminate\Database\Eloquent\SoftDeletes::class);
});

it('CommissionPayoutItem has no SoftDeletes trait', function () {
    $uses = class_uses_recursive(CommissionPayoutItem::class);
    expect($uses)->not->toHaveKey(\Illuminate\Database\Eloquent\SoftDeletes::class);
});
