<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Billing\WebhookEvent;
use App\Models\Core\Professional\Professional;
use App\Models\Retail\CommissionPayout;
use App\Services\Stripe\CommissionVoidService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

// V2: Core. Processes Stripe Connect events: account updates, checkout completions, transfer status, payment intents. Drives the commission payout lifecycle.
class StripeConnectWebhookController extends Controller
{
    use ValidatesStripeWebhookPayload;

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        if (! $sigHeader) {
            return response()->json(['error' => 'Missing signature'], 400);
        }

        // Try both Connect and platform webhook secrets so one endpoint URL
        // can handle events from connected accounts and destination charges.
        $secrets = array_filter([
            config('services.stripe.connect_webhook_secret'),
            config('services.stripe.webhook_secret'),
        ]);

        if ($secrets === []) {
            return response()->json(['error' => 'No webhook secret configured'], 400);
        }

        $event = null;
        foreach ($secrets as $secret) {
            try {
                $event = Webhook::constructEvent($payload, $sigHeader, $secret);
                break;
            } catch (SignatureVerificationException) {
                continue;
            } catch (\Exception $e) {
                Log::warning('Stripe webhook parse error', ['error' => $e->getMessage()]);

                return response()->json(['error' => 'Invalid payload'], 400);
            }
        }

        if (! $event) {
            Log::warning('Stripe webhook signature verification failed for all configured secrets');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        if (! $this->validateEventStructure($event)) {
            return response()->json(['error' => 'Invalid payload structure'], 400);
        }

        // Idempotency: firstOrCreate on the UNIQUE stripe_event_id. wasRecentlyCreated
        // distinguishes "won the race / first delivery" from "duplicate — skip".
        // Stripe event IDs are globally unique across platform + Connect events,
        // so billing.webhook_events covers both this controller and StripeWebhookController.
        // The row is committed before the match() dispatch: if a handler throws a 500,
        // Stripe's retry will find the existing row here and skip re-triggering the crash.
        $webhookEvent = WebhookEvent::firstOrCreate(
            ['stripe_event_id' => $event->id],
            ['event_type' => $event->type, 'processed_at' => now()]
        );

        if (! $webhookEvent->wasRecentlyCreated) {
            return response()->json(['received' => true]);
        }

        // Payload is HMAC-verified; set via forceFill (not mass-assignment) to preserve $fillable restriction.
        $webhookEvent->forceFill(['payload' => json_decode($payload, true)])->save();

        return $this->handleParsedEvent($event);
    }

    /**
     * Dispatch a verified, de-duplicated Stripe Event to the appropriate handler.
     * Extracted from __invoke() so it can be unit-tested without HMAC signing.
     *
     * Guards account-scoped event types by verifying the HMAC-signed top-level
     * `event->account` field matches `data.object->id`. A mismatch means the
     * payload was tampered with; we reject with 400 rather than mutating records.
     */
    public function handleParsedEvent(\Stripe\Event $event): JsonResponse
    {
        // For account-scoped events, event->account is the HMAC-signed source of truth.
        // If data.object.id differs, reject — payload could have been tampered with
        // to mutate a victim account using the attacker's valid HMAC.
        //
        // account.application.* events are excluded: their data.object is an Application
        // (id = ca_xxx), not an Account, so the id won't match event->account by design.
        $accountScopedPrefixes = ['account.', 'capability.'];
        $isAccountScoped = collect($accountScopedPrefixes)
            ->contains(fn ($p) => str_starts_with($event->type, $p));

        if ($isAccountScoped && ! str_starts_with($event->type, 'account.application.')) {
            $topLevelAccount = $event->account ?? null;
            $objectId = $event->data->object->id ?? null;

            if ($topLevelAccount === null || $topLevelAccount !== $objectId) {
                Log::warning('stripe.connect.account_mismatch', [
                    'event_id' => $event->id,
                    'event_account' => $topLevelAccount,
                    'object_id' => $objectId,
                ]);

                return response()->json(['error' => 'account_mismatch'], 400);
            }
        }

        match ($event->type) {
            'account.updated' => $this->handleAccountUpdated($event->data->object),
            'account.application.deauthorized' => $this->handleAccountDeauthorized((string) ($event->account ?? '')),
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event->data->object, (string) ($event->account ?? '')),
            'transfer.created' => $this->handleTransferCreated($event->data->object),
            'transfer.paid' => $this->handleTransferPaid($event->data->object),
            'transfer.failed' => $this->handleTransferFailed($event->data->object),
            'transfer.reversed' => $this->handleTransferReversed($event->data->object),
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object, (string) ($event->account ?? '')),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
            default => Log::debug('Unhandled Stripe Connect event', ['type' => $event->type]),
        };

        return response()->json(['received' => true]);
    }

    private function handleCheckoutSessionCompleted(object $session, string $connectedAccountId): void
    {
        $professionalId = $session->metadata?->professional_id ?? null;

        if (! $professionalId) {
            Log::warning('stripe.checkout_completed.missing_professional_id', [
                'session_id' => $session->id ?? null,
                'mode'       => $session->mode ?? null,
            ]);

            return;
        }

        $service = app(StripeConnectService::class);

        match ($session->mode ?? null) {
            'setup' => $service->syncPaymentMethodFromCheckoutSession(
                Professional::find($professionalId),
                $session->id
            ),
            // 'payment' arm wired in Phase A3.1; stub log here to avoid 500 errors on early delivery.
            'payment' => method_exists($service, 'creditWalletFromCheckoutSession')
                ? $service->creditWalletFromCheckoutSession($professionalId, $session)
                : Log::info('stripe.checkout_completed.payment_deferred', [
                    'session_id' => $session->id ?? null,
                    'phase'      => 'A2 stub; implementation lands in A3.1',
                ]),
            default => Log::warning('stripe.checkout_completed.unknown_mode', [
                'session_id' => $session->id ?? null,
                'mode'       => $session->mode ?? null,
            ]),
        };
    }

    /**
     * Handle account.application.deauthorized — fired when an Express account owner
     * revokes access via their Stripe dashboard. Mark them disconnected locally so
     * the payout job stops targeting their account and the UI surfaces the disconnect.
     *
     * Note: event->account is the connected account ID (HMAC-signed); event->data->object
     * is an Application object whose id will differ. The account-scope guard is skipped
     * for account.application.* events for this reason.
     */
    private function handleAccountDeauthorized(string $stripeAccountId): void
    {
        if (! $stripeAccountId) {
            Log::warning('stripe.connect.deauthorize_missing_account');

            return;
        }

        $professional = Professional::where('stripe_connect_account_id', $stripeAccountId)->first();

        if (! $professional) {
            Log::debug('Stripe account.application.deauthorized for unknown account', ['account_id' => $stripeAccountId]);

            return;
        }

        $professional->update(['stripe_connect_status' => 'disconnected']);

        Log::info('Stripe Connect account deauthorized via dashboard', [
            'professional_id' => $professional->id,
            'account_id' => $stripeAccountId,
        ]);
    }

    private function handleAccountUpdated(object $account): void
    {
        $professional = Professional::where('stripe_connect_account_id', $account->id)->first();

        if (! $professional) {
            Log::debug('Stripe account.updated for unknown account', ['account_id' => $account->id]);

            return;
        }

        // Respect local disconnect: if the professional has soft-disconnected
        // the account, ignore incoming Stripe events until they explicitly
        // reconnect via createOnboardingLink. Prevents a late account.updated
        // event from silently re-activating a disconnected account.
        if ($professional->stripe_connect_status === 'disconnected') {
            Log::debug('Stripe account.updated skipped — account locally disconnected', [
                'professional_id' => $professional->id,
                'account_id' => $account->id,
            ]);

            return;
        }

        $status = StripeConnectService::determineAccountStatus($account);

        if ($professional->stripe_connect_status !== $status) {
            $oldStatus = $professional->stripe_connect_status;

            // Atomic: if flushHeldCommissions throws, the status update is rolled back.
            // The professional stays in their prior state; the next account.updated
            // (new event_id, bypasses idempotency) retries the full transition cleanly.
            DB::transaction(function () use ($professional, $status, $oldStatus) {
                $professional->update(['stripe_connect_status' => $status]);

                // When an affiliate transitions to 'active', flush any held commissions
                // so they enter the normal payout pipeline immediately.
                if ($status === 'active' && $oldStatus !== 'active') {
                    app(CommissionVoidService::class)->flushHeldCommissions($professional);
                }
            });

            Log::info('Stripe Connect status updated', [
                'professional_id' => $professional->id,
                'old_status' => $oldStatus,
                'new_status' => $status,
            ]);
        }
    }

    private function handleTransferCreated(object $transfer): void
    {
        $payoutId = $transfer->metadata?->sidest_payout_id ?? null;

        if (! $payoutId) {
            return;
        }

        Log::info('Stripe transfer created', [
            'transfer_id' => $transfer->id,
            'payout_id' => $payoutId,
        ]);
    }

    /**
     * Handle transfer.paid — Stripe confirms funds have settled to the connected account.
     * Marks the payout 'completed', stamps transfer_completed_at, clears any prior error fields,
     * and bumps the analytics cache for both sides of the transaction.
     *
     * Idempotent: already-completed payouts are skipped. Payouts in a terminal failure state
     * (failed / cancelled / reversed) are logged and skipped — they should not be re-opened.
     */
    private function handleTransferPaid(object $transfer): void
    {
        $payoutId = $transfer->metadata?->sidest_payout_id ?? null;

        if (! $payoutId) {
            Log::warning('stripe.transfer_paid.missing_payout_metadata', ['transfer_id' => $transfer->id]);

            return;
        }

        $payout = CommissionPayout::find($payoutId);

        if (! $payout) {
            Log::warning('stripe.transfer_paid.payout_not_found', [
                'transfer_id' => $transfer->id,
                'payout_id'   => $payoutId,
            ]);

            return;
        }

        if ($payout->status === 'completed') {
            return; // idempotent
        }

        if (in_array($payout->status, ['failed', 'cancelled', 'reversed'], true)) {
            Log::warning('stripe.transfer_paid.unexpected_status', [
                'payout_id' => $payoutId,
                'status'    => $payout->status,
            ]);

            return;
        }

        $payout->forceFill([
            'status'                => 'completed',
            'transfer_completed_at' => now(),
            'failure_code'          => null,
            'failure_reason'        => null,
            'failure_category'      => null,
            'stripe_error_code'     => null,
            'stripe_error_message'  => null,
        ])->save();

        $analytics = app(\App\Services\Cache\AnalyticsCacheService::class);
        if ($payout->affiliate_professional_id) {
            $analytics->bumpAnalyticsVersion($payout->affiliate_professional_id);
        }
        if ($payout->brand_professional_id) {
            $analytics->bumpAnalyticsVersion($payout->brand_professional_id);
        }

        Log::info('stripe.transfer_paid', ['transfer_id' => $transfer->id, 'payout_id' => $payoutId]);
    }

    private function handleTransferFailed(object $transfer): void
    {
        $payoutId = $transfer->metadata?->sidest_payout_id ?? null;

        if (! $payoutId) {
            return;
        }

        $payout = CommissionPayout::find($payoutId);

        if (! $payout) {
            return;
        }

        if (in_array($payout->status, ['failed', 'completed', 'cancelled'], true)) {
            return;
        }

        $payout->forceFill([
            'status'               => 'failed',
            'failure_code'         => 'transfer_failed_webhook',
            'failure_reason'       => 'Transfer failed according to Stripe webhook',
            'failure_category'     => 'affiliate_account',
            'stripe_error_code'    => $transfer->failure_code ?? null,
            'stripe_error_message' => $transfer->failure_message ?? null,
        ])->save();

        if ($payout->affiliate_professional_id) {
            app(\App\Services\Cache\AnalyticsCacheService::class)
                ->bumpAnalyticsVersion($payout->affiliate_professional_id);
        }

        Log::warning('Stripe transfer failed', [
            'transfer_id' => $transfer->id,
            'payout_id'   => $payoutId,
        ]);
    }

    /**
     * Handle transfer.reversed — funds reached the affiliate and were subsequently
     * clawed back by Stripe (compliance hold, account closure, etc.).
     *
     * Unlike transfer.failed (funds never left), a reversal can arrive after the payout
     * is already 'completed'. The brand was charged; the affiliate's Stripe balance was
     * drained. The only safe action is to mark the payout 'reversed', flag it for manual
     * recovery, and surface it to ops — no auto-refund attempt here.
     */
    private function handleTransferReversed(object $transfer): void
    {
        $payoutId = $transfer->metadata?->sidest_payout_id ?? null;

        if (! $payoutId) {
            return;
        }

        $payout = CommissionPayout::find($payoutId);

        if (! $payout) {
            Log::warning('stripe.transfer_reversed.payout_not_found', [
                'transfer_id' => $transfer->id,
                'payout_id' => $payoutId,
            ]);

            return;
        }

        // Idempotent: a duplicate webhook for an already-reversed payout is a no-op.
        if ($payout->status === 'reversed') {
            return;
        }

        $previousStatus = $payout->status;

        // transfer.reversed can arrive after 'completed' — this is the most common
        // real-world scenario (transfer confirmed → payout marked complete → Stripe later
        // reverses). Completed payouts must be updatable here, unlike handleTransferFailed.
        $payout->forceFill([
            'status'               => 'reversed',
            'failure_code'         => 'transfer_reversed',
            'failure_reason'       => 'Transfer reversed by Stripe after delivery — funds clawed back',
            'needs_manual_refund'  => true,
            'stripe_error_code'    => $transfer->failure_code ?? null,
            'stripe_error_message' => $transfer->failure_message ?? null,
        ])->save();

        if ($payout->affiliate_professional_id) {
            app(\App\Services\Cache\AnalyticsCacheService::class)
                ->bumpAnalyticsVersion($payout->affiliate_professional_id);
        }

        Log::warning('stripe.transfer_reversed', [
            'transfer_id'     => $transfer->id,
            'payout_id'       => $payoutId,
            'previous_status' => $previousStatus,
        ]);
    }

    private function handlePaymentIntentSucceeded(object $paymentIntent, string $connectedAccountId = ''): void
    {
        // Commission payout payment intent
        $payoutId = $paymentIntent->metadata?->sidest_payout_id ?? null;
        if (! $payoutId) {
            return;
        }

        Log::info('Stripe payment intent succeeded for payout', [
            'payment_intent_id' => $paymentIntent->id,
            'payout_id' => $payoutId,
        ]);
    }

    private function handlePaymentIntentFailed(object $paymentIntent): void
    {
        $payoutId = $paymentIntent->metadata?->sidest_payout_id ?? null;

        if (! $payoutId) {
            return;
        }

        $payout = CommissionPayout::find($payoutId);
        if ($payout && ! in_array($payout->status, ['failed', 'completed'])) {
            $payout->forceFill([
                'status' => 'failed',
                'failure_code' => 'payment_failed_webhook',
                'failure_reason' => $paymentIntent->last_payment_error?->message ?? 'Payment failed',
            ])->save();
        }

        Log::warning('Stripe payment intent failed for payout', [
            'payment_intent_id' => $paymentIntent->id,
            'payout_id' => $payoutId,
        ]);
    }
}
