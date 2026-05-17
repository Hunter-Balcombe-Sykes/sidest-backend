<?php

use App\Http\Controllers\Api\Webhooks\Stripe\StripeWebhookController;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// -------------------------------------------------------------------
// Schema bootstrap
// -------------------------------------------------------------------

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');
    foreach ([
        'stripe_customer_id TEXT',
        'stripe_payment_method_id TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }
});

// -------------------------------------------------------------------
// Shared factory helpers (pmTest* prefix to avoid global collisions)
// -------------------------------------------------------------------

function pmTestBrand(string $pmId, string $customerId): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => "Test Brand {$id}",
        'professional_type' => 'brand',
        'status' => 'active',
        'stripe_customer_id' => $customerId,
        'stripe_payment_method_id' => $pmId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($id);
}

// -------------------------------------------------------------------
// Tests
// -------------------------------------------------------------------

it('payment_method.detached clears stripe_payment_method_id on matching brand', function () {
    $brand = pmTestBrand('pm_abc123', 'cus_xyz');

    $paymentMethod = (object) ['id' => 'pm_abc123', 'customer' => null];

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handlePaymentMethodDetached');
    $method->setAccessible(true);
    $method->invoke($controller, $paymentMethod);

    expect($brand->fresh()->stripe_payment_method_id)->toBeNull();
});

it('payment_method.detached leaves stripe_customer_id intact on the brand', function () {
    $brand = pmTestBrand('pm_abc456', 'cus_xyz2');

    $paymentMethod = (object) ['id' => 'pm_abc456', 'customer' => null];

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handlePaymentMethodDetached');
    $method->setAccessible(true);
    $method->invoke($controller, $paymentMethod);

    // Customer ID must remain so a new card can be attached to the same Stripe customer.
    expect($brand->fresh()->stripe_customer_id)->toBe('cus_xyz2');
});

it('payment_method.detached is a no-op when no brand matches the pm id', function () {
    $brand = pmTestBrand('pm_other', 'cus_other');

    $paymentMethod = (object) ['id' => 'pm_unknown', 'customer' => null];

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handlePaymentMethodDetached');
    $method->setAccessible(true);
    $method->invoke($controller, $paymentMethod);

    // Brand with a different PM ID must be untouched.
    expect($brand->fresh()->stripe_payment_method_id)->toBe('pm_other');
});

it('payment_method.detached is a no-op when payment method id is missing from event', function () {
    $brand = pmTestBrand('pm_should_stay', 'cus_stay');

    $paymentMethod = (object) ['id' => '', 'customer' => null];

    $controller = new StripeWebhookController;
    $method = new ReflectionMethod($controller, 'handlePaymentMethodDetached');
    $method->setAccessible(true);
    $method->invoke($controller, $paymentMethod);

    expect($brand->fresh()->stripe_payment_method_id)->toBe('pm_should_stay');
});
