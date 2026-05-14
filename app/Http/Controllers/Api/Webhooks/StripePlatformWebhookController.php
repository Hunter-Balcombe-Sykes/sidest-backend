<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Billing\WebhookEvent;
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
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

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
        $event = $this->verifyAndDedupe(
            $request,
            (string) config('services.stripe.platform_webhook_secret'),
        );

        if ($event instanceof JsonResponse) {
            return $event;
        }

        try {
            match ($event->type) {
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
                'charge.refunded' => $this->handleChargeRefunded($event->data->object),
                'charge.dispute.created' => $this->handleChargeDisputeCreated($event->data->object),
                default => Log::debug('Unhandled Stripe platform snapshot event', ['type' => $event->type]),
            };
        } catch (\Throwable $e) {
            // Delete the dedup row so Stripe's retry sees a fresh delivery, not a permanent 200.
            $this->currentWebhookEvent?->delete();
            throw $e;
        }

        return response()->json(['received' => true]);
    }

    /**
     * Thin endpoint — handles v2 platform-scope events. The payload only carries
     * data.related_object.id (the account ID); we re-fetch via syncAccountStatus to get
     * the full capability state.
     */
    public function thin(Request $request): JsonResponse
    {
        $event = $this->verifyAndDedupe(
            $request,
            (string) config('services.stripe.platform_thin_webhook_secret'),
        );

        if ($event instanceof JsonResponse) {
            return $event;
        }

        if (! str_starts_with($event->type, 'v2.core.account')) {
            Log::debug('Unhandled Stripe platform thin event', ['type' => $event->type]);

            return response()->json(['received' => true]);
        }

        try {
            $this->handleV2AccountEvent($event);
        } catch (\Throwable $e) {
            $this->currentWebhookEvent?->delete();
            throw $e;
        }

        return response()->json(['received' => true]);
    }

    /**
     * Verify HMAC + dedup the event. Returns the parsed Event on success, or a JsonResponse
     * with the appropriate error code when the request is invalid or already processed.
     */
    private function verifyAndDedupe(Request $request, string $secret): Event|JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        if (! $sigHeader) {
            return response()->json(['error' => 'Missing signature'], 400);
        }

        if ($secret === '') {
            Log::error('Stripe platform webhook hit with no secret configured', [
                'path' => $request->path(),
            ]);

            return response()->json(['error' => 'No webhook secret configured'], 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException) {
            Log::warning('Stripe platform webhook signature verification failed', [
                'path' => $request->path(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::warning('Stripe platform webhook parse error', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        }

        if (! $this->validateEventStructure($event)) {
            return response()->json(['error' => 'Invalid payload structure'], 400);
        }

        $webhookEvent = WebhookEvent::firstOrCreate(
            ['stripe_event_id' => $event->id],
            ['event_type' => $event->type, 'processed_at' => now()]
        );

        if (! $webhookEvent->wasRecentlyCreated) {
            return response()->json(['received' => true]);
        }

        $webhookEvent->forceFill(['payload' => json_decode($payload, true)])->save();
        $this->currentWebhookEvent = $webhookEvent;

        return $event;
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
     * charge.refunded — informational only.
     *
     * The clawback row is written synchronously inside CommissionPayoutRefundService::
     * clawbackCompletedPayout at the time we call refunds->create, using our local
     * proportional estimate of the application_fee_refund / transfer_reversal split.
     * This webhook arrives later carrying Stripe's actual allocation, but we don't
     * currently reconcile our estimate against it — we just log. A future enhancement
     * could diff `$charge->amount_refunded` against the clawback row and flag drift,
     * but for now the local estimate is treated as authoritative.
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

        // STRP-4: reconcile Stripe's authoritative allocation against our proportional estimate.
        // Rounding differences > 1 cent indicate the fee_ratio math diverged from Stripe's internal
        // rounding and should be flagged for month-end reconciliation.
        $firstRefund = $charge->refunds->data[0] ?? null;
        if (! $firstRefund) {
            return;
        }

        $stripeFeeCents = isset($firstRefund->application_fee_refund->amount)
            ? (int) $firstRefund->application_fee_refund->amount
            : null;
        $stripeTransferCents = isset($firstRefund->transfer_reversal->amount)
            ? (int) $firstRefund->transfer_reversal->amount
            : null;

        if ($stripeFeeCents === null && $stripeTransferCents === null) {
            return;
        }

        $clawback = CommissionClawback::where('payout_id', $payoutId)
            ->where('refund_id', (string) $firstRefund->id)
            ->first();

        if (! $clawback) {
            return;
        }

        $feeDrift = $stripeFeeCents !== null
            ? abs((int) $clawback->application_fee_refund_cents - $stripeFeeCents)
            : 0;
        $transferDrift = $stripeTransferCents !== null
            ? abs((int) $clawback->transfer_reversal_cents - $stripeTransferCents)
            : 0;

        if ($feeDrift > 1 || $transferDrift > 1) {
            Log::warning('stripe.platform.clawback_drift', [
                'payout_id' => $payoutId,
                'refund_id' => $firstRefund->id,
                'estimated_fee_refund_cents' => $clawback->application_fee_refund_cents,
                'stripe_fee_refund_cents' => $stripeFeeCents,
                'fee_drift_cents' => $feeDrift,
                'estimated_transfer_reversal_cents' => $clawback->transfer_reversal_cents,
                'stripe_transfer_reversal_cents' => $stripeTransferCents,
                'transfer_drift_cents' => $transferDrift,
            ]);
        }
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
     * Thin payload carries data.related_object.id (the account ID). The sync itself
     * re-fetches the full v2 account state so we don't need event body fields.
     */
    private function handleV2AccountEvent(Event $event): void
    {
        $accountId = (string) (
            $event->data->related_object->id
                ?? $event->data->object->id
                ?? ''
        );

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

        // syncAccountStatus internally retrieves the v2 account and persists the derived
        // dual-capability status. We don't read event body fields — thin payload doesn't
        // carry them anyway.
        app(StripeConnectService::class)->syncAccountStatus($professional);
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
