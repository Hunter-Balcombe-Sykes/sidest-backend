<?php

use App\Jobs\Shopify\Gdpr\RedactCustomerJob;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Postgres-only: exercises the jsonb_strip_pii path in RedactCustomerJob.
// The function does not exist in SQLite so the test must be skipped on non-pgsql connections.
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires Postgres jsonb_strip_pii function.');
    }
});

/**
 * Seed a minimal fixture: one brand integration, one customer, one order with
 * PII in multiple shopify_data paths, one order_event with PII in metadata.
 *
 * @return array{professionalId: string, customerId: string, orderId: string, eventId: string, gdprId: string}
 */
function seedRedactCustomerOrdersFixture(): array
{
    $professionalId = (string) Str::uuid();
    $shopDomain = 'alice-brand-'.Str::random(6).'.myshopify.com';
    $customerId = (string) Str::uuid();
    $orderId = (string) Str::uuid();
    $eventId = (string) Str::uuid();
    $now = now()->toIso8601String();

    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'access_token' => 'shpat_test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('core.customers')->insert([
        'id' => $customerId,
        'professional_id' => $professionalId,
        'email' => 'alice@example.com',
        'phone' => '+1234',
        'full_name' => 'Alice Smith',
        'source' => 'shopify',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // shopify_data contains PII in standard fields AND customer-authored free-text fields.
    $shopifyData = [
        'customer' => [
            'email' => 'alice@example.com',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'phone' => '+1234',
        ],
        'billing_address' => [
            'address1' => '123 Main St',
            'city' => 'Melbourne',
            'name' => 'Alice Smith',
        ],
        'shipping_address' => [
            'address1' => '456 Other Rd',
            'city' => 'Sydney',
            'name' => 'Alice Smith',
        ],
        'note' => 'Customer prefers morning delivery',
        'note_attributes' => [
            ['name' => 'delivery_window', 'value' => 'alice@home'],
        ],
        'line_items' => [
            [
                'id' => 1,
                'properties' => [
                    ['name' => 'engraving', 'value' => 'For Alice Smith'],
                ],
            ],
        ],
        'currency' => 'AUD',
    ];

    DB::table('commerce.orders')->insert([
        'id' => $orderId,
        'shopify_order_id' => 'gid://shopify/Order/999',
        'shopify_shop_domain' => $shopDomain,
        'brand_professional_id' => $professionalId,
        'affiliate_professional_id' => (string) Str::uuid(),
        'customer_id' => $customerId,
        'status' => 'paid',
        'gross_cents' => 5000,
        'discount_cents' => 0,
        'refund_cents' => 0,
        'net_cents' => 5000,
        'commission_cents' => 500,
        'commission_rate' => 0.10,
        'rate_source' => 'brand_default',
        'currency_code' => 'AUD',
        'line_items' => json_encode($shopifyData['line_items']),
        'shopify_data' => json_encode($shopifyData),
        'shopify_updated_at' => $now,
        'occurred_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // order_events row with PII in metadata: refund note + denormalized customer object.
    $metadata = [
        'refund' => [
            'id' => 'r1',
            'note' => 'Customer alice@example.com requested refund',
        ],
        'customer' => [
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'phone' => '+1234',
        ],
        'amount_cents' => 500,
    ];

    DB::table('commerce.order_events')->insert([
        'id' => $eventId,
        'order_id' => $orderId,
        'event_type' => 'refund_created',
        'amount_delta_cents' => -500,
        'metadata' => json_encode($metadata),
        'source' => 'webhook',
        'shopify_triggered_at' => $now,
    ]);

    $gdpr = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_CUSTOMERS_REDACT,
        'shop_domain' => $shopDomain,
        'payload_hash' => hash('sha256', 'alice-'.$professionalId),
        'payload' => [
            'shop_domain' => $shopDomain,
            'customer' => ['id' => 42, 'email' => 'alice@example.com', 'phone' => '+1234'],
            'orders_to_redact' => [],
        ],
        'received_at' => now(),
    ]);

    return [
        'professionalId' => $professionalId,
        'customerId' => $customerId,
        'orderId' => $orderId,
        'eventId' => $eventId,
        'gdprId' => $gdpr->id,
    ];
}

it('NULLs customer_id on the matching order', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderId'])->first();
    expect($order->customer_id)->toBeNull();
});

it('redacts customer PII fields in shopify_data', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderId'])->first();
    $data = json_decode($order->shopify_data, true);

    expect($data['customer']['email'])->toBe('REDACTED');
    expect($data['customer']['first_name'])->toBe('REDACTED');
    expect($data['customer']['last_name'])->toBe('REDACTED');
    expect($data['customer']['phone'])->toBe('REDACTED');
});

it('redacts billing_address and shipping_address entirely', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderId'])->first();
    $data = json_decode($order->shopify_data, true);

    // billing_address and shipping_address keys are replaced with the string "REDACTED"
    // (jsonb_strip_pii plain-path branch replaces the entire value at the given path).
    expect($data['billing_address'])->toBe('REDACTED');
    expect($data['shipping_address'])->toBe('REDACTED');
});

it('redacts the order note', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderId'])->first();
    $data = json_decode($order->shopify_data, true);

    expect($data['note'])->toBe('REDACTED');
});

it('redacts note_attributes[*].value but preserves note_attributes[*].name', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderId'])->first();
    $data = json_decode($order->shopify_data, true);

    // value (customer-authored, PII) → redacted
    expect($data['note_attributes'][0]['value'])->toBe('REDACTED');

    // name (merchant-authored field label) → preserved
    expect($data['note_attributes'][0]['name'])->toBe('delivery_window');
});

it('redacts line_items[*].properties[*].value but preserves non-PII line item fields', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderId'])->first();
    $data = json_decode($order->shopify_data, true);

    // properties[*].value (customer-authored, e.g. engraving text) → redacted
    expect($data['line_items'][0]['properties'][0]['value'])->toBe('REDACTED');

    // Non-PII fields on the line item are preserved
    expect($data['line_items'][0]['id'])->toBe(1);
});

it('preserves non-PII fields in shopify_data', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    $order = DB::table('commerce.orders')->where('id', $ctx['orderId'])->first();
    $data = json_decode($order->shopify_data, true);

    expect($data['currency'])->toBe('AUD');
});

it('redacts PII fields in order_events metadata', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    $event = DB::table('commerce.order_events')->where('id', $ctx['eventId'])->first();
    $meta = json_decode($event->metadata, true);

    expect($meta['refund']['note'])->toBe('REDACTED');
    expect($meta['customer']['email'])->toBe('REDACTED');
    expect($meta['customer']['name'])->toBe('REDACTED');
    expect($meta['customer']['phone'])->toBe('REDACTED');
});

it('preserves non-PII fields in order_events metadata', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    $event = DB::table('commerce.order_events')->where('id', $ctx['eventId'])->first();
    $meta = json_decode($event->metadata, true);

    // refund.id is not PII — it's a Shopify internal ID
    expect($meta['refund']['id'])->toBe('r1');

    // amount_cents is a financial figure, not PII
    expect($meta['amount_cents'])->toBe(500);
});

it('marks the gdpr request completed', function () {
    $ctx = seedRedactCustomerOrdersFixture();

    (new RedactCustomerJob($ctx['gdprId']))->handle();

    expect(GdprRequest::find($ctx['gdprId'])->status)->toBe(GdprRequest::STATUS_COMPLETED);
});
