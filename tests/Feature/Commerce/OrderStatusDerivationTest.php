<?php

use App\Http\Controllers\Api\Professional\Affiliate\AffiliateOrdersController;
use App\Http\Controllers\Api\Professional\Brand\BrandOrdersController;

// Tests for the 4-state order lifecycle derivation surfaced by both
// BrandOrdersController + AffiliateOrdersController.
//
// States: pending | processing | paid | reversed
//
// Decision tree (matches deriveLifecycleStatus() in both controllers):
//   1. order_status in [cancelled, voided, refunded]              → reversed
//   2. refund_cents >= net_cents AND net_cents > 0                → reversed
//   3. payout_id IS NULL                                           → pending
//   4. payout_status in [failed, cancelled]                        → pending  (released for re-batch)
//   5. payout_status === 'completed'                               → paid
//   6. otherwise (payout in pending/processing)                    → processing
//
// The cancelled/voided/refunded branches are defensive — the controller's WHERE clause
// excludes those order statuses upstream (Order::EXCLUDED_FROM_AGGREGATES) so they never
// reach the helper in the LIST path. We test all branches directly via reflection so the
// derivation logic is fully covered regardless of upstream filtering.

/**
 * Invoke the private deriveLifecycleStatus helper on a controller via reflection. Both
 * controllers carry identical implementations — we exercise both so a future drift in
 * one is caught.
 *
 * @param  array<string, mixed>  $rowAttrs  fields a SELECT row would carry
 */
function statusDeriv_callHelper(string $controllerClass, array $rowAttrs): string
{
    $controller = new $controllerClass;
    // BrandOrdersController and AffiliateOrdersController both extend the same base.
    // Pass no constructor args — the method we're calling doesn't touch them.

    $row = (object) $rowAttrs;

    $method = (new ReflectionClass($controller))->getMethod('deriveLifecycleStatus');
    $method->setAccessible(true);

    return $method->invoke($controller, $row);
}

/** Build a row object with sensible defaults for all fields the helper reads. */
function statusDeriv_row(array $overrides = []): array
{
    return array_merge([
        'order_status' => 'approved',
        'refund_cents' => 0,
        'net_cents' => 10000,
        'payout_id' => null,
        'payout_status' => null,
    ], $overrides);
}

dataset('controllers', [
    BrandOrdersController::class,
    AffiliateOrdersController::class,
]);

it('reversed when order_status is cancelled', function (string $controllerClass) {
    $row = statusDeriv_row(['order_status' => 'cancelled']);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('reversed');
})->with('controllers');

it('reversed when order_status is voided', function (string $controllerClass) {
    $row = statusDeriv_row(['order_status' => 'voided']);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('reversed');
})->with('controllers');

it('reversed when order_status is refunded', function (string $controllerClass) {
    $row = statusDeriv_row(['order_status' => 'refunded']);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('reversed');
})->with('controllers');

it('reversed when refund_cents equals net_cents (full refund)', function (string $controllerClass) {
    $row = statusDeriv_row(['refund_cents' => 10000, 'net_cents' => 10000]);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('reversed');
})->with('controllers');

it('reversed when refund_cents exceeds net_cents (over-refund edge)', function (string $controllerClass) {
    $row = statusDeriv_row(['refund_cents' => 12000, 'net_cents' => 10000]);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('reversed');
})->with('controllers');

it('NOT reversed when refund_cents matches net_cents but net is zero (legacy zero-net order)', function (string $controllerClass) {
    $row = statusDeriv_row(['refund_cents' => 0, 'net_cents' => 0]);
    // Both zero → not the "full refund" branch (which requires net_cents > 0).
    // Falls through to the no-payout branch → pending.
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('pending');
})->with('controllers');

it('pending when payout_id is null', function (string $controllerClass) {
    $row = statusDeriv_row(['payout_id' => null]);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('pending');
})->with('controllers');

it('pending when payout_status is failed (orders released for re-batch)', function (string $controllerClass) {
    $row = statusDeriv_row(['payout_id' => 'p_abc', 'payout_status' => 'failed']);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('pending');
})->with('controllers');

it('pending when payout_status is cancelled', function (string $controllerClass) {
    $row = statusDeriv_row(['payout_id' => 'p_abc', 'payout_status' => 'cancelled']);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('pending');
})->with('controllers');

it('paid when payout_status is completed', function (string $controllerClass) {
    $row = statusDeriv_row(['payout_id' => 'p_abc', 'payout_status' => 'completed']);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('paid');
})->with('controllers');

it('processing when payout_status is pending (PI not yet created)', function (string $controllerClass) {
    $row = statusDeriv_row(['payout_id' => 'p_abc', 'payout_status' => 'pending']);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('processing');
})->with('controllers');

it('processing when payout_status is processing (PI in flight at Stripe)', function (string $controllerClass) {
    $row = statusDeriv_row(['payout_id' => 'p_abc', 'payout_status' => 'processing']);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('processing');
})->with('controllers');

it('reversed beats payout status when order_status is reversal-terminal', function (string $controllerClass) {
    // Defensive: even if a payout exists and is "completed", a refunded order should surface
    // as reversed in the UI (the refund flow downstream will reconcile the money).
    $row = statusDeriv_row([
        'order_status' => 'refunded',
        'payout_id' => 'p_abc',
        'payout_status' => 'completed',
    ]);
    expect(statusDeriv_callHelper($controllerClass, $row))->toBe('reversed');
})->with('controllers');
