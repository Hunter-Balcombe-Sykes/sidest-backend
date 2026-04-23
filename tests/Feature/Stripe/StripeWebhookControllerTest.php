<?php

// Tests for the HTTP entry point of StripeWebhookController — specifically the
// signature verification gate. Business logic is covered in StripeWebhookSubscriptionUpdatedTest.

it('returns 400 when Stripe-Signature header is missing', function () {
    $this->postJson('/api/webhooks/stripe', ['type' => 'customer.subscription.created'])
        ->assertStatus(400)
        ->assertJson(['error' => 'Missing signature']);
});

it('returns 400 when webhook secret is not configured', function () {
    config(['services.stripe.webhook_secret' => null]);

    $this->postJson('/api/webhooks/stripe', ['type' => 'customer.subscription.created'], [
        'Stripe-Signature' => 't=1234567890,v1=fakehash',
    ])
        ->assertStatus(400)
        ->assertJson(['error' => 'No webhook secret configured']);
});

it('returns 400 when signature does not match', function () {
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);

    // t=1 is far outside Stripe's 300s tolerance window, triggering SignatureVerificationException.
    $this->postJson('/api/webhooks/stripe', ['type' => 'customer.subscription.created'], [
        'Stripe-Signature' => 't=1,v1=badhash',
    ])
        ->assertStatus(400)
        ->assertJson(['error' => 'Invalid signature']);
});
