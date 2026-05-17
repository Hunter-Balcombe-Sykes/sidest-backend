<?php

use App\Models\Commerce\BrandAffiliateRollup;
use App\Models\Commerce\CommissionMovement;
use App\Models\Commerce\CommissionPayout;
use App\Models\Commerce\Order;
use App\Models\Commerce\OrderEvent;
use App\Models\Commerce\OrderItem;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;

it('Order points at commerce.orders and casts money columns to int', function () {
    $order = new Order;

    expect($order->getTable())->toBe('commerce.orders')
        ->and($order->getKeyType())->toBe('string')
        ->and($order->getIncrementing())->toBeFalse();

    $casts = $order->getCasts();
    foreach (['gross_cents', 'discount_cents', 'refund_cents', 'net_cents', 'commission_cents'] as $col) {
        expect($casts[$col] ?? null)->toBe('integer', "Expected {$col} cast to integer");
    }
    expect($casts['line_items'] ?? null)->toBe('array')
        ->and($casts['shopify_data'] ?? null)->toBe('array')
        ->and($casts['shopify_updated_at'] ?? null)->toBe('datetime')
        ->and($casts['occurred_at'] ?? null)->toBe('datetime');
});

it('Order has the expected BelongsTo relationships', function () {
    $order = new Order;

    expect($order->brandProfessional()->getRelated())->toBeInstanceOf(Professional::class)
        ->and($order->brandProfessional()->getForeignKeyName())->toBe('brand_professional_id')
        ->and($order->affiliateProfessional()->getRelated())->toBeInstanceOf(Professional::class)
        ->and($order->affiliateProfessional()->getForeignKeyName())->toBe('affiliate_professional_id')
        ->and($order->customer()->getRelated())->toBeInstanceOf(Customer::class)
        ->and($order->payout()->getRelated())->toBeInstanceOf(CommissionPayout::class);
});

it('Order has events and items HasMany relationships', function () {
    $order = new Order;

    expect($order->events()->getRelated())->toBeInstanceOf(OrderEvent::class)
        ->and($order->events()->getForeignKeyName())->toBe('order_id')
        ->and($order->items()->getRelated())->toBeInstanceOf(OrderItem::class)
        ->and($order->items()->getForeignKeyName())->toBe('order_id');
});

it('OrderEvent points at commerce.order_events and disables timestamps', function () {
    $event = new OrderEvent;

    expect($event->getTable())->toBe('commerce.order_events')
        ->and($event->timestamps)->toBeFalse();

    $casts = $event->getCasts();
    expect($casts['amount_delta_cents'] ?? null)->toBe('integer')
        ->and($casts['metadata'] ?? null)->toBe('array')
        ->and($casts['shopify_triggered_at'] ?? null)->toBe('datetime');
});

it('OrderItem points at commerce.order_items, casts money to int and floats correctly', function () {
    $item = new OrderItem;

    expect($item->getTable())->toBe('commerce.order_items')
        ->and($item->timestamps)->toBeFalse();

    $casts = $item->getCasts();
    foreach (['quantity', 'unit_price_cents', 'discount_cents', 'line_total_cents', 'commission_cents'] as $col) {
        expect($casts[$col] ?? null)->toBe('integer', "Expected {$col} cast to integer");
    }
    expect($casts['commission_rate'] ?? null)->toBe('float');
});

it('BrandAffiliateRollup is a composite-key model without timestamps', function () {
    $rollup = new BrandAffiliateRollup;

    expect($rollup->getTable())->toBe('commerce.brand_affiliate_rollup')
        ->and($rollup->getIncrementing())->toBeFalse()
        ->and($rollup->getKeyName())->toBeNull()
        ->and($rollup->timestamps)->toBeFalse();

    $casts = $rollup->getCasts();
    expect($casts['day'] ?? null)->toBe('date');
    foreach (['orders_count', 'gross_cents', 'refund_cents', 'net_cents',
        'commission_cents', 'reversed_commission_cents'] as $col) {
        expect($casts[$col] ?? null)->toBe('integer', "Expected {$col} cast to integer");
    }
});

it('CommissionMovement points at commerce.commission_movements (rename deferred to Phase 4)', function () {
    $movement = new CommissionMovement;

    // Phase 4 cleanup renames the table to commerce.commission_movements and updates this expectation.
    expect($movement->getTable())->toBe('commerce.commission_movements')
        ->and($movement->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($movement->order()->getForeignKeyName())->toBe('order_id');
});

it('the legacy CommissionMovement continues pointing at commerce.commission_movements', function () {
    $legacy = new \App\Models\Commerce\CommissionMovement;

    expect($legacy->getTable())->toBe('commerce.commission_movements');
});
