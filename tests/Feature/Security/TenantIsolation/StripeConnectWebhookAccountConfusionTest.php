<?php

use App\Http\Controllers\Api\Webhooks\StripeConnectWebhookController;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

beforeEach(function () {
    tenantHelpersEnsureTables();

    // setupProfessionalsTable() omits Stripe-specific columns; add them here.
    // SQLite silently errors on duplicate columns — wrap each in try/catch.
    foreach (['stripe_connect_account_id', 'stripe_connect_status'] as $col) {
        try {
            DB::connection('pgsql')->statement(
                "ALTER TABLE core.professionals ADD COLUMN {$col} TEXT NULL"
            );
        } catch (\Throwable) {
            // Already exists — ignore.
        }
    }

    // billing schema + idempotency table for handleParsedEvent's insertOrIgnore path
    try {
        DB::connection('pgsql')->statement("ATTACH DATABASE ':memory:' AS billing");
    } catch (\Throwable) {
        // Already attached — ignore
    }

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        stripe_event_id TEXT UNIQUE,
        event_type TEXT,
        payload TEXT,
        processed_at TEXT
    )');
});

it('rejects an account.updated event whose data.object.id does not match event.account', function () {
    $victim = createBrandTenant('victim-stripe');

    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $victim->id)
        ->update(['stripe_connect_account_id' => 'acct_VICTIM']);

    // Craft a mismatched event: attacker account at top level, victim's at data.object
    $fakeEvent = Event::constructFrom([
        'id'      => 'evt_fake_mismatch',
        'type'    => 'account.updated',
        'account' => 'acct_ATTACKER',
        'data'    => [
            'object' => [
                'id'                => 'acct_VICTIM',
                'charges_enabled'   => true,
                'payouts_enabled'   => false,
                'details_submitted' => false,
                'object'            => 'account',
            ],
        ],
    ]);

    $controller = app(StripeConnectWebhookController::class);
    $response = $controller->handleParsedEvent($fakeEvent);

    expect($response->getStatusCode())->toBe(400);
});

it('processes account.updated when event.account matches data.object.id', function () {
    $legitimate = createBrandTenant('legit-stripe');

    DB::connection('pgsql')->table('core.professionals')
        ->where('id', $legitimate->id)
        ->update(['stripe_connect_account_id' => 'acct_LEGIT']);

    // Legitimate event: top-level account matches data.object.id
    $fakeEvent = Event::constructFrom([
        'id'      => 'evt_fake_legit',
        'type'    => 'account.updated',
        'account' => 'acct_LEGIT',
        'data'    => [
            'object' => [
                'id'                => 'acct_LEGIT',
                'charges_enabled'   => true,
                'payouts_enabled'   => false,
                'details_submitted' => false,
                'object'            => 'account',
            ],
        ],
    ]);

    $controller = app(StripeConnectWebhookController::class);
    $response = $controller->handleParsedEvent($fakeEvent);

    // Should return 200 received:true (or anything that's not 400)
    expect($response->getStatusCode())->not->toBe(400);
});
