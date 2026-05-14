<?php

namespace App\Http\Controllers\Api\Webhooks;

use Illuminate\Support\Facades\Log;
use Stripe\Event;

// Guards against valid-HMAC payloads that are structurally incomplete.
// constructEvent() validates signature + JSON parse but doesn't enforce field presence.
//
// Stripe v1 (snapshot) events carry the full object in data.object. v2 thin events
// carry only data.related_object (a reference to the object — caller must re-fetch).
// We accept either shape so the same trait works for both the snapshot and thin webhook
// endpoints.
trait ValidatesStripeWebhookPayload
{
    private function validateEventStructure(Event $event): bool
    {
        $hasObject = ($event->data->object ?? null) !== null
            || ($event->data->related_object ?? null) !== null;

        $valid = ! empty($event->id)
            && ! empty($event->type)
            && $event->data !== null
            && $hasObject;

        if (! $valid) {
            Log::warning('Stripe webhook rejected: missing required event fields', [
                'event_id' => $event->id ?? null,
                'event_type' => $event->type ?? null,
                'has_data' => $event->data !== null,
                'has_data_object' => ($event->data->object ?? null) !== null,
                'has_data_related_object' => ($event->data->related_object ?? null) !== null,
            ]);
        }

        return $valid;
    }
}
