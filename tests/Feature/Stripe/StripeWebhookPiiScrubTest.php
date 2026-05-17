<?php

use App\Http\Controllers\Api\Webhooks\Stripe\StripeWebhookController;

// STRP-L: customer PII scrubber. Stripe subscription + invoice events include
// the customer's name, email, billing address, and saved card details inline
// with the resource snapshot. Storing those verbatim creates a PII-at-rest
// concern (and bloats provider_payload to MBs per row at scale). The scrubber
// redacts the sensitive keys before persistence; everything else (IDs, status
// fields, timestamps) is preserved so the dedup ledger stays useful for
// incident debugging.

it('redacts customer PII fields from a sanitised event payload', function () {
    $event = (object) [
        'id' => 'evt_pii_scrub_test',
        'type' => 'customer.subscription.created',
        'created' => time(),
        'data' => (object) [
            'object' => (object) [
                'id' => 'sub_pii_test',
                'customer' => 'cus_pii_test',
                'status' => 'active',
                'metadata' => (object) ['sidest_professional_id' => 'pro_test'],
                'items' => (object) ['data' => [
                    (object) [
                        'id' => 'si_1',
                        'price' => (object) ['id' => 'price_test'],
                        // current_period_start/end are scalars — should NOT be redacted.
                        'current_period_start' => 1700000000,
                        'current_period_end' => 1702592000,
                    ],
                ]],
                'billing_details' => (object) [
                    'name' => 'PII Customer',
                    'email' => 'leak@example.com',
                    'address' => (object) ['line1' => '1 PII Way', 'city' => 'Sydney'],
                ],
                'shipping' => (object) [
                    'name' => 'Should Be Redacted',
                    'address' => (object) ['line1' => '2 PII Way'],
                ],
                'tax_ids' => ['au_abn' => '12 345 678 901'],
            ],
        ],
        'livemode' => false,
    ];

    $controller = app(StripeWebhookController::class);
    $method = new ReflectionMethod($controller, 'sanitizeForStorage');
    $method->setAccessible(true);
    $scrubbed = $method->invoke($controller, $event);

    // Non-sensitive top-level / identifying fields are preserved.
    expect($scrubbed['id'])->toBe('evt_pii_scrub_test')
        ->and($scrubbed['type'])->toBe('customer.subscription.created')
        ->and($scrubbed['data']['object']['id'])->toBe('sub_pii_test')
        ->and($scrubbed['data']['object']['customer'])->toBe('cus_pii_test')
        ->and($scrubbed['data']['object']['status'])->toBe('active')
        ->and($scrubbed['data']['object']['metadata']['sidest_professional_id'])->toBe('pro_test')
        ->and($scrubbed['data']['object']['items']['data'][0]['price']['id'])->toBe('price_test')
        ->and($scrubbed['data']['object']['items']['data'][0]['current_period_start'])->toBe(1700000000);

    // PII fields redacted at every nesting level.
    expect($scrubbed['data']['object']['billing_details'])->toBe('[REDACTED]')
        ->and($scrubbed['data']['object']['shipping'])->toBe('[REDACTED]')
        ->and($scrubbed['data']['object']['tax_ids'])->toBe('[REDACTED]');
});

it('redacts saved card / BECS / SEPA payment method details', function () {
    $event = (object) [
        'id' => 'evt_pm_scrub',
        'type' => 'payment_method.attached',
        'data' => (object) [
            'object' => (object) [
                'id' => 'pm_card_pii',
                'type' => 'card',
                'card' => (object) [
                    'brand' => 'visa',
                    'last4' => '4242',
                    'exp_month' => 12,
                    'exp_year' => 2030,
                    'fingerprint' => 'xxxx',
                ],
                'au_becs_debit' => (object) ['bsb_number' => '062001', 'last4' => '1234'],
                'sepa_debit' => (object) ['country' => 'DE', 'last4' => '4321'],
            ],
        ],
    ];

    $controller = app(StripeWebhookController::class);
    $method = new ReflectionMethod($controller, 'sanitizeForStorage');
    $method->setAccessible(true);
    $scrubbed = $method->invoke($controller, $event);

    // Top-level identifier preserved.
    expect($scrubbed['data']['object']['id'])->toBe('pm_card_pii')
        ->and($scrubbed['data']['object']['type'])->toBe('card');

    // All three payment-method-detail blocks redacted.
    expect($scrubbed['data']['object']['card'])->toBe('[REDACTED]')
        ->and($scrubbed['data']['object']['au_becs_debit'])->toBe('[REDACTED]')
        ->and($scrubbed['data']['object']['sepa_debit'])->toBe('[REDACTED]');
});
