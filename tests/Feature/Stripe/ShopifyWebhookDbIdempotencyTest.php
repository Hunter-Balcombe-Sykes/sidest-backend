<?php

use App\Http\Controllers\Concerns\DedupesShopifyWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Exercises the DedupesShopifyWebhookEvent trait. Stripe webhook controllers
// already get atomicity from Laravel 12's firstOrCreate; this trait gives
// Shopify the same durable dedup beneath the existing Cache::add fast-path.

beforeEach(function () {
    attachTestSchemas();

    // webhook_events table with the provider column added by the new migration.
    // SQLite test DB doesn't support CREATE INDEX ON schema.table — express the
    // composite unique as a table-level constraint instead.
    $conn = DB::connection('pgsql');
    $conn->statement('CREATE TABLE IF NOT EXISTS billing.webhook_events (
        id TEXT PRIMARY KEY,
        provider TEXT NOT NULL DEFAULT \'stripe\',
        stripe_event_id TEXT NOT NULL,
        event_type TEXT NOT NULL,
        payload TEXT,
        received_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        processed_at TEXT NULL,
        UNIQUE (provider, stripe_event_id)
    )');
});

/**
 * Anonymous class that pulls in the trait so we can call its private method
 * via reflection without standing up a full HTTP controller.
 */
function newDedupeTester(): object
{
    return new class
    {
        use DedupesShopifyWebhookEvent;

        public function call(string $webhookId, string $topic): bool
        {
            return $this->claimShopifyWebhookEvent($webhookId, $topic);
        }
    };
}

it('claims a Shopify webhook event on first call', function () {
    $tester = newDedupeTester();
    $webhookId = (string) Str::uuid();

    expect($tester->call($webhookId, 'refunds/create'))->toBeTrue();

    $row = DB::table('billing.webhook_events')
        ->where('provider', 'shopify')
        ->where('stripe_event_id', $webhookId)
        ->first();
    expect($row)->not->toBeNull()
        ->and($row->event_type)->toBe('refunds/create');
});

it('returns false on duplicate webhook id', function () {
    $tester = newDedupeTester();
    $webhookId = (string) Str::uuid();

    expect($tester->call($webhookId, 'refunds/create'))->toBeTrue()
        ->and($tester->call($webhookId, 'refunds/create'))->toBeFalse();

    // Only one row inserted despite two calls.
    $count = DB::table('billing.webhook_events')
        ->where('provider', 'shopify')
        ->where('stripe_event_id', $webhookId)
        ->count();
    expect($count)->toBe(1);
});

it('allows the same id across different providers', function () {
    $tester = newDedupeTester();
    $sharedId = 'evt_collision_test';

    // Seed a Stripe-side row with the same logical id.
    DB::table('billing.webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'provider' => 'stripe',
        'stripe_event_id' => $sharedId,
        'event_type' => 'transfer.paid',
        'received_at' => now()->toDateTimeString(),
    ]);

    // Shopify dedup should claim it because the composite key is (provider, id).
    expect($tester->call($sharedId, 'refunds/create'))->toBeTrue();
});

it('lets callers proceed when webhook id header is empty', function () {
    $tester = newDedupeTester();

    // No header → no durable dedup possible. Caller still runs (Cache::add is the fallback).
    expect($tester->call('', 'refunds/create'))->toBeTrue()
        ->and($tester->call('   ', 'refunds/create'))->toBeTrue();

    // Nothing was written to the DB.
    expect(DB::table('billing.webhook_events')->where('provider', 'shopify')->count())->toBe(0);
});
