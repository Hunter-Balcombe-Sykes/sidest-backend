<?php

namespace App\Http\Controllers\Api\Webhooks;

use Illuminate\Support\Facades\Log;
use Stripe\Event;

// Guards against valid-HMAC payloads that are structurally incomplete.
// constructEvent() validates signature + JSON parse but doesn't enforce field presence.
trait ValidatesStripeWebhookPayload
{
    private function validateEventStructure(Event $event): bool
    {
        $valid = ! empty($event->id)
            && ! empty($event->type)
            && $event->data !== null
            && ($event->data->object ?? null) !== null;

        if (! $valid) {
            Log::warning('Stripe webhook rejected: missing required event fields', [
                'event_id' => $event->id ?? null,
                'event_type' => $event->type ?? null,
                'has_data' => $event->data !== null,
                'has_data_object' => ($event->data->object ?? null) !== null,
            ]);
        }

        return $valid;
    }
}
