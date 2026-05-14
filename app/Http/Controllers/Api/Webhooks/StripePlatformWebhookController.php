<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Commerce\CommissionClawback;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionPayoutRefundService;
use App\Services\Stripe\CommissionPayoutService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * Stripe v2 destination-charge platform webhooks.
 *
 * The new Event Destinations system splits platform delivery by payload style: v1 events
 * (payment_intent.*, charge.*) ship as snapshot, v2 events (v2.core.account.*) ship as
 * thin. Each destination has its own signing secret, so we serve them on separate routes
 * with separate verification.
 *
 *   POST /api/webhooks/stripe-platform        → __invoke()  (snapshot, v1 events)
 *   POST /api/webhooks/stripe-platform-thin   → thin()      (thin, v2 events)
 *
 * Both methods dedupe on stripe_event_id via the shared billing.webhook_events table.
 * payment_intent.* handlers gate on metadata.sidest_payout_id so subscription PIs (handled
 * by StripeWebhookController on /api/webhooks/stripe) pass through as no-ops.
 */
class StripePlatformWebhookController extends Controller
{
    use ValidatesStripeWebhookPayload;

    // Held across the request so __invoke/thin can delete it when a handler throws.
    private ?WebhookEvent $currentWebhookEvent = null;

    public function __construct(
        private readonly CommissionPayoutService $payoutService,
        private readonly CommissionPayoutRefundService $refundService,
    ) {}

    /**
     * Snapshot endpoint — handles v1 platform-scope events.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $event = $this->constructStripeEvent(
            $request,
            (string) config('services.stripe.platform_webhook_secret'),
        );
        if ($event instanceof JsonResponse) {
            return $event;
        }

        $webhookEvent = $this->dedupeOrAck($event, $request->getContent());
        if ($webhookEvent instanceof JsonResponse) {
            return $webhookEvent;
        }

        $this->runHandlerWithFailureCleanup($webhookEvent, function () use ($event): void {
            match ($event->type) {
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
                'charge.refunded' => $this->handleChargeRefunded($event->data->object),
                'charge.dispute.created' => $this->handleChargeDisputeCreated($event->data->object),
                default => Log::debug('Unhandled Stripe platform snapshot event', ['type' => $event->type]),
            };
        });

        return response()->json(['received' => true]);
    }

    /**
     * Thin endpoint — handles v2 platform-scope events. The payload carries
     * related_object.id at the top level (the account ID); we re-fetch via
     * syncAccountStatus to get the full capability state.
     */
    public function thin(Request $request): JsonResponse
    {
        $event = $this->constructStripeEvent(
            $request,
            (string) config('services.stripe.platform_thin_webhook_secret'),
        );
        if ($event instanceof JsonResponse) {
            return $event;
        }

        $webhookEvent = $this->dedupeOrAck($event, $request->getContent());
        if ($webhookEvent instanceof JsonResponse) {
            return $webhookEvent;
        }

        $this->runHandlerWithFailureCleanup($webhookEvent, function () use ($event): void {
            if (! str_starts_with($event->type, 'v2.core.account')) {
                Log::debug('Unhandled Stripe platform thin event', ['type' => $event->type]);

                return;
            }

            $this->handleV2AccountEvent($event);
        });

        return response()->json(['received' => true]);
    }

    /**
     * payment_intent.succeeded — destination charge captured. Advance the linked payout
     * to 'completed'. Subscription PIs (no sidest_payout_id metadata) pass through.
     */
    private function handlePaymentIntentSucceeded(object $paymentIntent): void
    {
        $payoutId = $paymentIntent->metadata?->sidest_payout_id ?? null;
        if (! $payoutId) {
            return;
        }

        $payout = CommissionPayout::find($payoutId);
        if (! $payout) {
            Log::warning('stripe.platform.pi_succeeded.payout_not_found', [
                'payment_intent_id' => $paymentIntent->id,
                'payout_id' => $payoutId,
            ]);

            return;
        }

        $chargeId = $this->extractLatestChargeId($paymentIntent);
        $this->payoutService->markPaymentIntentSucceeded($payout, $chargeId);
    }

    /**
     * payment_intent.payment_failed — card declined or BECS rejected at T+2. Fail the
     * linked payout. Subscription PIs pass through.
     */
    private function handlePaymentIntentFailed(object $paymentIntent): void
    {
        $payoutId = $paymentIntent->metadata?->sidest_payout_id ?? null;
        if (! $payoutId) {
            return;
        }

        $payout = CommissionPayout::find($payoutId);
        if (! $payout) {
            Log::warning('stripe.platform.pi_failed.payout_not_found', [
                'payment_intent_id' => $paymentIntent->id,
                'payout_id' => $payoutId,
            ]);

            return;
        }

        $lastError = $paymentIntent->last_payment_error ?? null;
        $code = (string) ($lastError->code ?? $lastError->decline_code ?? 'payment_failed');
        $message = (string) ($lastError->message ?? 'Payment failed without further detail from Stripe.');

        $this->payoutService->markPaymentIntentFailed($payout, $code, $message);
    }

    /**
     * charge.refunded — informational log + reconciliation of the clawback row's
     * application_fee_refund_cents / transfer_reversal_cents against Stripe's actual
     * allocation (STRP-J).
     *
     * Flow: the clawback row is written synchronously inside CommissionPayoutRefundService::
     * clawbackCompletedPayout at refunds->create time, using our local proportional
     * estimate. Stripe applies its own rounding when it actually issues the
     * application_fee_refund / transfer_reversal, which can differ from our estimate
     * by ±1 cent. This webhook arrives later carrying the ground truth — we diff,
     * log drift > 1 cent for Nightwatch alerting, and persist Stripe's authoritative
     * values so monthly reconciliation queries don't have to apply tolerance logic.
     *
     * Subscription billing refunds (no sidest_payout_id metadata) pass through silently.
     */
    private function handleChargeRefunded(object $charge): void
    {
        $payoutId = $charge->metadata?->sidest_payout_id ?? null;
        if (! $payoutId) {
            return;
        }

        Log::info('stripe.platform.charge_refunded', [
            'charge_id' => $charge->id,
            'payout_id' => $payoutId,
            'amount_refunded' => $charge->amount_refunded ?? null,
            'refunded' => $charge->refunded ?? null,
        ]);

        $stripeRefund = $charge->refunds->data[0] ?? null;
        if ($stripeRefund === null || ! isset($stripeRefund->id)) {
            return;
        }

        $stripeFeeRefund = (int) ($stripeRefund->application_fee_refund->amount ?? 0);
        $stripeTransferReversal = (int) ($stripeRefund->transfer_reversal->amount ?? 0);

        $clawback = CommissionClawback::query()
            ->where('payout_id', $payoutId)
            ->where('refund_id', $stripeRefund->id)
            ->first();

        if ($clawback === null) {
            Log::info('stripe.platform.clawback_drift.no_local_row', [
                'payout_id' => $payoutId,
                'refund_id' => $stripeRefund->id,
            ]);

            return;
        }

        $feeDrift = abs($stripeFeeRefund - (int) $clawback->application_fee_refund_cents);
        $transferDrift = abs($stripeTransferReversal - (int) $clawback->transfer_reversal_cents);

        if ($feeDrift > 1 || $transferDrift > 1) {
            Log::warning('stripe.platform.clawback_drift', [
                'payout_id' => $payoutId,
                'refund_id' => $stripeRefund->id,
                'local_fee_refund_cents' => (int) $clawback->application_fee_refund_cents,
                'stripe_fee_refund_cents' => $stripeFeeRefund,
                'fee_drift_cents' => $feeDrift,
                'local_transfer_reversal_cents' => (int) $clawback->transfer_reversal_cents,
                'stripe_transfer_reversal_cents' => $stripeTransferReversal,
                'transfer_drift_cents' => $transferDrift,
            ]);
        }

        $clawback->forceFill([
            'application_fee_refund_cents' => $stripeFeeRefund,
            'transfer_reversal_cents' => $stripeTransferReversal,
        ])->save();
    }

    /**
     * charge.dispute.created — buyer disputed the charge. The platform bears the loss
     * (losses_collector=application) so we flag for ops + halt any in-flight retries.
     */
    private function handleChargeDisputeCreated(object $dispute): void
    {
        $payoutId = $dispute->metadata?->sidest_payout_id ?? null;

        // Dispute metadata may be empty — we can also resolve via the charge ID.
        if (! $payoutId && isset($dispute->charge)) {
            $payout = CommissionPayout::where('charge_id', (string) $dispute->charge)->first();
            $payoutId = $payout?->id;
        } else {
            $payout = $payoutId ? CommissionPayout::find($payoutId) : null;
        }

        if (! $payout) {
            Log::warning('stripe.platform.dispute_created.no_matching_payout', [
                'dispute_id' => $dispute->id,
                'charge_id' => $dispute->charge ?? null,
            ]);

            return;
        }

        $payout->forceFill([
            'needs_manual_refund' => true,
            'failure_code' => 'dispute_opened',
            'failure_reason' => sprintf('Stripe dispute opened (id=%s, reason=%s)', $dispute->id, $dispute->reason ?? 'unknown'),
        ])->save();

        Log::warning('stripe.platform.dispute_created.flagged_for_manual_review', [
            'dispute_id' => $dispute->id,
            'payout_id' => $payout->id,
            'reason' => $dispute->reason ?? null,
        ]);
    }

    /**
     * Resolve the Professional from a v2 account event and trigger a status sync.
     *
     * v2 thin payloads put related_object at the TOP level — sibling to id/type/created.
     * (data may exist on v2 events but carries event-specific scalars like account_id,
     * NOT the v1-style full snapshot.) The sync itself re-fetches the full v2 account
     * state so we don't read event body fields beyond related_object.id.
     */
    private function handleV2AccountEvent(Event $event): void
    {
        $accountId = (string) ($event->related_object->id ?? '');

        if ($accountId === '') {
            Log::warning('stripe.platform.v2_account_event.missing_account_id', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            return;
        }

        StripeConnectService::forgetStatusCache($accountId);

        $professional = Professional::where('stripe_connect_account_id', $accountId)->first();
        if (! $professional) {
            Log::debug('Stripe v2 account event for unknown account', [
                'event_type' => $event->type,
                'account_id' => $accountId,
            ]);

            return;
        }

        if ($event->type === 'v2.core.account.closed') {
            $professional->update([
                'stripe_connect_account_id' => null,
                'stripe_connect_status' => 'not_connected',
                'stripe_payment_method_id' => null,
                'stripe_payment_method_brand' => null,
                'stripe_payment_method_last4' => null,
                'payout_method' => null,
            ]);

            Log::info('Stripe v2 account closed', [
                'professional_id' => $professional->id,
                'account_id' => $accountId,
            ]);

            return;
        }

        // Respect local disconnect — late events shouldn't silently re-activate the account.
        if ($professional->stripe_connect_status === 'not_connected') {
            return;
        }

        // STRP-F: dispatch the v2 account retrieve as an async job so the webhook
        // returns 200 in milliseconds — Stripe's ~25s webhook timeout otherwise risks
        // a slow Accounts API hit timing out the webhook → retry → silenced. The job's
        // ShouldBeUnique key debounces back-to-back events for the same account; the
        // sync itself runs syncAccountStatus (v2 retrieve + dual-capability derive +
        // DB persist) with its own retry budget.
        \App\Jobs\Stripe\SyncStripeAccountStatusJob::dispatch($professional->id);
    }

    private function extractLatestChargeId(object $paymentIntent): ?string
    {
        if (is_string($paymentIntent->latest_charge ?? null) && $paymentIntent->latest_charge !== '') {
            return $paymentIntent->latest_charge;
        }

        if (is_object($paymentIntent->latest_charge ?? null) && is_string($paymentIntent->latest_charge->id ?? null)) {
            return $paymentIntent->latest_charge->id;
        }

        return null;
    }
}
