<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// DB-backed dedup for Shopify webhooks. Cache::add is the fast path for the
// common case (true duplicates within minutes); this trait adds a durable
// dedup layer that survives Redis flushes and Shopify retries beyond the
// 24h cache TTL.
//
// Uses billing.webhook_events with provider='shopify'. UNIQUE index on
// (provider, stripe_event_id) — column kept named stripe_event_id for
// backward compatibility, semantically an external_event_id.
trait DedupesShopifyWebhookEvent
{
    /**
     * Claim a Shopify webhook event in the DB. Returns true if this is the first
     * delivery seen (caller should proceed), false if a previous delivery has
     * already been recorded (caller should ack 200 and skip work).
     *
     * Pass the X-Shopify-Webhook-Id (canonical Shopify dedup header) and the
     * Shopify topic (e.g. "refunds/create") for event_type tagging.
     */
    private function claimShopifyWebhookEvent(string $webhookId, string $topic): bool
    {
        $webhookId = trim($webhookId);
        if ($webhookId === '') {
            // Without the header we can't dedupe via DB — fall through to processing.
            // Cache::add (in the caller) is the only safety net in this rare case.
            return true;
        }

        $inserted = DB::table('billing.webhook_events')->insertOrIgnore([
            'id' => Str::uuid()->toString(),
            'provider' => 'shopify',
            'stripe_event_id' => $webhookId,
            'event_type' => $topic,
            'received_at' => now(),
        ]);

        return $inserted > 0;
    }
}
