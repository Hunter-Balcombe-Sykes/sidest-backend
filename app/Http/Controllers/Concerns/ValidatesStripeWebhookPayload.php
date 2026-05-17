<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Billing\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

// Shared HMAC verification, structural validation, dedup, and delete-on-failure
// handler invocation for all three Stripe webhook endpoints. The three steps are
// kept as distinct trait methods so each controller can interleave its own
// structural checks (e.g. Connect's account_mismatch guard) between them.
//
// Payload shapes by event type:
//
//   v1 snapshot (payment_intent.*, account.updated, customer.subscription.*, ...):
//     full Stripe object lives at data.object. We require it.
//
//   v2 thin (v2.core.account.*, v2.core.account_person.*, ...):
//     related_object lives at the TOP level — sibling to id/type/created. The data
//     field may exist on v2 events but carries event-specific scalars (e.g. account_id),
//     NOT the v1-style full snapshot. We require top-level related_object.
//
// Stripe SDK reference: vendor/stripe/stripe-php/lib/V2/Core/EventNotification.php
// (the v2-specific class declares related_object as a protected top-level property).
trait ValidatesStripeWebhookPayload
{
    /**
     * Verify HMAC + parse the Stripe Event from the request. Returns the Event on
     * success, or a JsonResponse (400) on missing signature / missing secret /
     * signature mismatch / parse error / structural validation failure.
     */
    private function constructStripeEvent(Request $request, string $secret): Event|JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        if (! $sigHeader) {
            return response()->json(['error' => 'Missing signature'], 400);
        }

        if ($secret === '') {
            Log::error('Stripe webhook hit with no secret configured', [
                'path' => $request->path(),
            ]);

            return response()->json(['error' => 'No webhook secret configured'], 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException) {
            Log::warning('Stripe webhook signature verification failed', [
                'path' => $request->path(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::warning('Stripe webhook parse error', [
                'path' => $request->path(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Invalid payload'], 400);
        }

        if (! $this->validateEventStructure($event)) {
            return response()->json(['error' => 'Invalid payload structure'], 400);
        }

        return $event;
    }

    /**
     * Look up or create the dedup row for this verified event.
     *
     * Returns the WebhookEvent on first delivery (caller proceeds to
     * runHandlerWithFailureCleanup), or a 200 JsonResponse if Stripe re-delivered an
     * event we've already received.
     *
     * received_at is set on creation. processed_at stays NULL until the handler
     * completes via runHandlerWithFailureCleanup — so ops can distinguish "we accepted
     * the event but the handler didn't finish" from "accepted AND handled" when
     * investigating stuck rows.
     */
    private function dedupeOrAck(Event $event, string $payload): WebhookEvent|JsonResponse
    {
        $webhookEvent = WebhookEvent::firstOrCreate(
            ['stripe_event_id' => $event->id],
            ['event_type' => $event->type, 'received_at' => now()],
        );

        if (! $webhookEvent->wasRecentlyCreated) {
            return response()->json(['received' => true]);
        }

        // Payload set via forceFill (not mass-assignment) to preserve $fillable restriction.
        $webhookEvent->forceFill(['payload' => json_decode($payload, true)])->save();

        return $webhookEvent;
    }

    /**
     * Run a webhook handler with delete-on-failure semantics (STRP-C).
     *
     * Success path: mark processed_at=now() on the dedup row so ops can distinguish
     * "handler completed" from "received but handler failed" when investigating
     * stuck or unprocessable events.
     *
     * Failure path: DROP the dedup row before re-throwing. Stripe's retry then sees
     * no dedup hit and re-runs the handler instead of being acked immediately as a
     * duplicate. Without this, any transient handler failure (DB deadlock, transient
     * Stripe API error, etc.) silences the event forever — combined with the daily
     * sweep this is the source of stuck-processing payouts.
     *
     * The dedup row delete is itself wrapped in a try/catch — if the database is
     * unhealthy we log loudly but still re-throw the original handler error so
     * Stripe retries (and Nightwatch surfaces the original incident).
     */
    private function runHandlerWithFailureCleanup(WebhookEvent $webhookEvent, \Closure $handler): void
    {
        try {
            $handler();
            $webhookEvent->forceFill(['processed_at' => now()])->save();
        } catch (\Throwable $e) {
            try {
                $webhookEvent->delete();
            } catch (\Throwable $deleteEx) {
                Log::error('stripe.webhook.dedup_row_delete_failed', [
                    'stripe_event_id' => $webhookEvent->stripe_event_id,
                    'event_type' => $webhookEvent->event_type,
                    'original_error' => $e->getMessage(),
                    'delete_error' => $deleteEx->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    private function validateEventStructure(Event $event): bool
    {
        if (empty($event->id) || empty($event->type)) {
            $this->logWebhookRejection($event, 'missing_id_or_type', []);

            return false;
        }

        if (str_starts_with((string) $event->type, 'v2.')) {
            if (($event->related_object ?? null) === null) {
                $this->logWebhookRejection($event, 'v2_missing_related_object', [
                    'shape' => 'v2_thin',
                ]);

                return false;
            }

            return true;
        }

        if (($event->data->object ?? null) === null) {
            $this->logWebhookRejection($event, 'v1_missing_data_object', [
                'shape' => 'v1_snapshot',
                'has_data' => $event->data !== null,
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function logWebhookRejection(?Event $event, string $reason, array $extra): void
    {
        Log::warning('Stripe webhook rejected: '.$reason, array_merge([
            'event_id' => $event?->id,
            'event_type' => $event?->type,
        ], $extra));
    }
}
